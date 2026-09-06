<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\GlobalHealthCheckMessage;
use App\Tests\Fixtures\Messenger\MiddlewareExecutionProbe;
use App\Tests\Fixtures\ObjectStorage\ObjectStorageTestCase;
use App\Tests\Fixtures\ObjectStorage\ReadStoredObjectMessage;
use App\Tests\Fixtures\ObjectStorage\StorageWorkerProbe;
use App\Tests\Fixtures\Scheduler\SchedulerExecutionProbe;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;

final class ObjectStorageMessengerTest extends ObjectStorageTestCase
{
    public function testReferencesCrossRealDoctrineSerializationAndOneReusedWorker(): void
    {
        $a = $this->allocate($this->tenantA);
        $this->storage->write($a, 'worker A');
        $b = $this->allocate($this->tenantB);
        $this->storage->write($b, 'worker B');
        $container = self::getContainer();
        $bus = self::service(MessageBusInterface::class);
        $transport = $container->get('messenger.transport.object_storage_probe');
        self::assertTrue($transport instanceof TransportInterface);
        $connection = self::service(Connection::class);
        self::assertSame(0, $this->queuedMessages($connection), 'The dedicated proof queue must start empty.');
        $probe = self::service(StorageWorkerProbe::class);
        $probe->outgoing = [$a, $b];
        $global = self::service(SchedulerExecutionProbe::class);
        $globalBefore = $global->handledCount();

        $this->context->setTenant($this->tenantA);
        $bus->dispatch(new ReadStoredObjectMessage($a));
        $this->context->setTenant($this->tenantB);
        $bus->dispatch(new ReadStoredObjectMessage($b));
        // A technical reference is not authorization: the worker must reject it
        // under B even though the message serialized successfully.
        $bus->dispatch(new ReadStoredObjectMessage($a));
        $this->context->clear();
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->read($a));
        $this->context->setTenant($this->tenantA);
        $bus->dispatch(new ReadStoredObjectMessage($a, true));
        $globalEnvelope = $bus->dispatch(new Envelope(new GlobalHealthCheckMessage(), [new TransportNamesStamp('object_storage_probe')]));
        self::assertNull($globalEnvelope->last(TenantStamp::class), 'RC11 global dispatch must not inherit the active tenant.');
        self::assertSame(5, $this->queuedMessages($connection));
        self::assertSame([], $probe->reads, 'No application handler may run before persistent consumption.');

        $events = new EventDispatcher();
        $events->addSubscriber(new StopWorkerOnMessageLimitListener(5));
        $handled = 0;
        $failed = 0;
        $events->addListener(WorkerMessageHandledEvent::class, function () use (&$handled): void {
            ++$handled;
            self::assertNull($this->context->getTenant(), 'Success must leave no tenant context.');
        });
        $events->addListener(WorkerMessageFailedEvent::class, function () use (&$failed): void {
            ++$failed;
            self::assertNull($this->context->getTenant(), 'Failure must leave no tenant context.');
        });
        $calls = $this->ioCalls();
        $middleware = self::service(MiddlewareExecutionProbe::class);
        $middlewareBefore = count($middleware->events);
        (new Worker(['object_storage_probe' => $transport], $bus, $events))->run(['sleep' => 0]);
        self::assertSame(3, $this->ioCalls() - $calls, 'The foreign reference must fail before adapter I/O.');
        foreach (array_slice($middleware->events, $middlewareBefore) as [$stage, $class, $tenant]) {
            if (GlobalHealthCheckMessage::class === $class) {
                self::assertNull($tenant, 'Global handling must have no residual tenant.');
            }
        }
        self::assertSame(3, $handled);
        self::assertSame(2, $failed);
        self::assertTrue($probe->allReferencesRestored, 'The worker must receive new, validated reference instances.');
        self::assertSame([
            ['tenant' => (string) $this->tenantA->getId(), 'digest' => hash('sha256', 'worker A')],
            ['tenant' => (string) $this->tenantB->getId(), 'digest' => hash('sha256', 'worker B')],
            ['tenant' => (string) $this->tenantA->getId(), 'digest' => hash('sha256', 'worker A')],
        ], $probe->reads);
        self::assertSame($globalBefore + 1, $global->handledCount());
        self::assertNull($this->context->getTenant());
        self::assertSame(0, $this->queuedMessages($connection));
        $this->context->setTenant($this->tenantA);
        self::assertSame('worker A', $this->storage->read($a));
        $this->context->clear();
        self::assertNull($this->context->getTenant());
    }

    private function queuedMessages(Connection $connection): int
    {
        $count = $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'object_storage_probe'");
        self::assertTrue(is_int($count) || is_string($count));

        return (int) $count;
    }
}
