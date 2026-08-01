<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Notification;
use App\Entity\Tenant;
use App\Message\SendNotificationMessage;
use App\MessageHandler\SendNotificationMessageHandler;
use App\Service\TenantMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

final class BundlePublicIntegrationsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        self::assertSame(0, (new CommandTester($application->find('app:create-sample-data')))->execute([]));

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testSharedCacheUsesDistinctTenantNamespacesAndFailsWithoutContext(): void
    {
        $cache = self::getContainer()->get(CacheItemPoolInterface::class);
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');

        $this->tenantContext->setTenant($tenantA);
        $itemA = $cache->getItem('dashboard.summary');
        $itemA->set('tenant-a-value');
        self::assertTrue($cache->save($itemA));

        $this->tenantContext->setTenant($tenantB);
        self::assertFalse($cache->getItem('dashboard.summary')->isHit());
        $itemB = $cache->getItem('dashboard.summary');
        $itemB->set('tenant-b-value');
        self::assertTrue($cache->save($itemB));
        self::assertSame('tenant-b-value', $cache->getItem('dashboard.summary')->get());

        $this->tenantContext->setTenant($tenantA);
        self::assertSame('tenant-a-value', $cache->getItem('dashboard.summary')->get());
        self::assertTrue($cache->deleteItem('dashboard.summary'));

        $this->tenantContext->setTenant($tenantB);
        self::assertSame('tenant-b-value', $cache->getItem('dashboard.summary')->get());
        self::assertTrue($cache->deleteItem('dashboard.summary'));

        $this->tenantContext->clear();
        $this->expectException(TenantCacheException::class);
        $cache->getItem('dashboard.summary');
    }

    public function testLocalStorageUsesRealTenantNamespacesAndRejectsTraversal(): void
    {
        $storage = self::getContainer()->get(TenantFileStorageInterface::class);
        $sourceA = tempnam(sys_get_temp_dir(), 'tenant-a-');
        $sourceB = tempnam(sys_get_temp_dir(), 'tenant-b-');
        self::assertIsString($sourceA);
        self::assertIsString($sourceB);
        file_put_contents($sourceA, 'tenant-a-content');
        file_put_contents($sourceB, 'tenant-b-content');

        try {
            $this->tenantContext->setTenant($this->tenant('tenant-a'));
            $pathA = $storage->upload(new File($sourceA), 'integration/shared-name.txt');
            self::assertSame('tenants/tenant-a/integration/shared-name.txt', $pathA);
            self::assertSame('tenant-a-content', file_get_contents($storage->getPath('integration/shared-name.txt')));

            $this->tenantContext->setTenant($this->tenant('tenant-b'));
            $pathB = $storage->upload(new File($sourceB), 'integration/shared-name.txt');
            self::assertSame('tenants/tenant-b/integration/shared-name.txt', $pathB);
            self::assertSame('tenant-b-content', file_get_contents($storage->getPath('integration/shared-name.txt')));
            self::assertNotSame($pathA, $pathB);

            try {
                $storage->getPath('../tenant-a/integration/shared-name.txt');
                self::fail('Traversal must be rejected.');
            } catch (TenantStorageException) {
                self::assertTrue(true);
            }

            $storage->delete('integration/shared-name.txt');
            $this->tenantContext->setTenant($this->tenant('tenant-a'));
            self::assertSame('tenant-a-content', file_get_contents($storage->getPath('integration/shared-name.txt')));
            $storage->delete('integration/shared-name.txt');

            $this->tenantContext->clear();
            $this->expectException(TenantStorageException::class);
            $storage->exists('integration/shared-name.txt');
        } finally {
            @unlink($sourceA);
            @unlink($sourceB);
        }
    }

    public function testMailerUsesBundleDecoratorAndKeepsTenantSendersSeparated(): void
    {
        $mailer = self::getContainer()->get(TenantMailerService::class);

        $this->tenantContext->setTenant($this->tenant('tenant-a'));
        $mailer->sendSimpleEmail('recipient@example.test', 'A message', 'Tenant A body');
        $envelopeA = $this->onlySentEnvelope();
        self::assertSame((string) $this->tenant('tenant-a')->getId(), $envelopeA->last(TenantStamp::class)?->getTenantId());
        $emailA = $this->emailFrom($envelopeA->getMessage());
        self::assertSame('noreply@tenant-a.example.com', $emailA->getFrom()[0]->getAddress());
        self::assertFalse($emailA->getHeaders()->has('X-Tenant-ID'));
        self::assertFalse($emailA->getHeaders()->has('X-Tenant-Name'));

        $this->transport()->reset();
        $this->tenantContext->setTenant($this->tenant('tenant-b'));
        $mailer->sendSimpleEmail('recipient@example.test', 'B message', 'Tenant B body');
        $envelopeB = $this->onlySentEnvelope();
        self::assertSame((string) $this->tenant('tenant-b')->getId(), $envelopeB->last(TenantStamp::class)?->getTenantId());
        $emailB = $this->emailFrom($envelopeB->getMessage());
        self::assertSame('noreply@tenant-b.example.com', $emailB->getFrom()[0]->getAddress());
        self::assertNotSame($emailA->getFrom()[0]->getAddress(), $emailB->getFrom()[0]->getAddress());
    }

    public function testMailerFailsClosedWithoutTenantContext(): void
    {
        $this->tenantContext->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tenant context available for sending email');
        self::getContainer()->get(TenantMailerService::class)
            ->sendSimpleEmail('recipient@example.test', 'No tenant', 'Must not be sent');
    }

    public function testMessengerRetainsContextAndHandlerRejectsMismatchedTenant(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');
        $notificationA = $this->notification($tenantA, 'Tenant A async');
        $notificationB = $this->notification($tenantB, 'Tenant B untouched');
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $this->tenantContext->setTenant($tenantA);
        $bus->dispatch(new SendNotificationMessage((int) $notificationA->getId()));
        $queued = $this->onlySentEnvelope();
        self::assertSame((string) $tenantA->getId(), $queued->last(TenantStamp::class)?->getTenantId());
        self::assertCount(1, $queued->all(TenantStamp::class));

        $this->tenantContext->clear();
        try {
            self::getContainer()->get(SendNotificationMessageHandler::class)($queued->getMessage());
            self::fail('A message without restored tenant context must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Notification processing requires a restored tenant context.', $exception->getMessage());
        }
        self::assertSame(Notification::STATUS_PENDING, $notificationA->getStatus());

        $tampered = new Envelope(
            new SendNotificationMessage((int) $notificationA->getId()),
            [new TenantStamp((string) $tenantB->getId()), new ReceivedStamp('async')],
        );
        try {
            $bus->dispatch($tampered);
            self::fail('A message stamped for another tenant must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Notification does not belong to the restored tenant context.', $exception->getMessage());
        }
        self::assertNull($this->tenantContext->getTenant());
        self::assertSame(Notification::STATUS_PENDING, $notificationA->getStatus());
        self::assertSame(Notification::STATUS_PENDING, $notificationB->getStatus());

        $bus->dispatch($queued->with(new ReceivedStamp('async')));
        self::assertNull($this->tenantContext->getTenant());
        self::assertSame(Notification::STATUS_SENT, $notificationA->getStatus());
        self::assertSame(Notification::STATUS_PENDING, $notificationB->getStatus());
    }

    private function notification(Tenant $tenant, string $title): Notification
    {
        $notification = (new Notification())
            ->setTenant($tenant)
            ->setTitle($title)
            ->setMessage($title)
            ->setSendEmail(false)
            ->setSendInApp(true);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    private function onlySentEnvelope(): \Symfony\Component\Messenger\Envelope
    {
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);

        return $sent[0];
    }

    private function emailFrom(object $message): Email
    {
        self::assertInstanceOf(SendEmailMessage::class, $message);
        $email = $message->getMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
