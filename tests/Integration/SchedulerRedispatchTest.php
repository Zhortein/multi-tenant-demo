<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\GlobalHealthCheckMessage;
use App\Schedule;
use App\Tests\Fixtures\Messenger\MiddlewareExecutionProbe;
use App\Tests\Fixtures\Scheduler\SchedulerExecutionProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Validator\Constraints\Callback;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class SchedulerRedispatchTest extends KernelTestCase
{
    private const QUEUE_NAME = 'scheduler_persistent';

    public static function outcomes(): iterable
    {
        yield 'success' => [false];
        yield 'handler exception' => [true];
    }

    #[DataProvider('outcomes')]
    public function testSchedulerWorkerPersistsBeforeApplicationWorkerHandles(bool $failure): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => self::QUEUE_NAME],
        );

        $context = $container->get(TenantContextInterface::class);
        self::assertInstanceOf(TenantContextInterface::class, $context);
        $bus = $container->get('messenger.bus.default');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $persistentTransport = $container->get('messenger.transport.scheduler_persistent');
        self::assertInstanceOf(TransportInterface::class, $persistentTransport);
        $schedule = $container->get(Schedule::class);
        self::assertInstanceOf(Schedule::class, $schedule);
        $probe = $container->get(SchedulerExecutionProbe::class);
        self::assertInstanceOf(SchedulerExecutionProbe::class, $probe);
        $probe->failOnHandle = $failure;
        $middleware = $container->get(MiddlewareExecutionProbe::class);
        $validator = $container->get('validator');
        foreach ([RedispatchMessage::class, GlobalHealthCheckMessage::class] as $class) {
            $validator->getMetadataFor($class)->addConstraint(new Callback(static function (object $message) use ($middleware): void {
                $middleware->record('validation', $message);
            }));
        }

        $clock = new MockClock('2026-09-04 12:00:00 UTC');
        $generator = new MessageGenerator($schedule, 'health_check', $clock);
        iterator_to_array($generator->getMessages());
        $clock->modify('+1 hour');

        self::assertSame([], $this->runOne(new SchedulerTransport($generator), $bus));
        self::assertSame(0, $probe->handledCount(), 'The Scheduler Worker must not call the application handler.');
        self::assertSame(1, $this->queuedMessages($connection));
        self::assertNull($context->getTenant());

        self::assertSame([
            ['validation', RedispatchMessage::class, null],
            ['before', RedispatchMessage::class, null],
            ['validation', GlobalHealthCheckMessage::class, null],
            ['before', GlobalHealthCheckMessage::class, null],
            ['after', GlobalHealthCheckMessage::class, null],
            ['after', RedispatchMessage::class, null],
        ], $middleware->events);

        $failures = $this->runOne($persistentTransport, $bus);
        self::assertCount($failure ? 1 : 0, $failures);
        if ($failure) {
            self::assertStringContainsString('Controlled Scheduler application-handler failure.', $failures[0]->getMessage());
        }
        self::assertSame(1, $probe->handledCount());
        self::assertSame(0, $this->queuedMessages($connection));
        self::assertNull($context->getTenant());
        self::assertSame([
            ['validation', GlobalHealthCheckMessage::class, null],
            ['before', GlobalHealthCheckMessage::class, null],
            ['after', GlobalHealthCheckMessage::class, null],
        ], array_slice($middleware->events, 6));
    }

    /** @return list<\Throwable> */
    private function runOne(TransportInterface $transport, MessageBusInterface $bus): array
    {
        $failures = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $dispatcher->addListener(
            WorkerMessageFailedEvent::class,
            static function (WorkerMessageFailedEvent $event) use (&$failures): void {
                $failures[] = $event->getThrowable();
            },
        );

        (new Worker(['proof' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        return $failures;
    }

    private function queuedMessages(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => self::QUEUE_NAME],
        );
    }
}
