<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Tenant;
use App\Message\SendNotificationMessage;
use App\Tests\Fixtures\Message\AttributeRoutedTenantMessage;
use App\Tests\Fixtures\Message\ConfiguredAndAttributedTenantMessage;
use App\Tests\Fixtures\Message\SynchronousTenantMessage;
use App\Tests\Fixtures\Messenger\RoutingProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class MessengerNativeRoutingStrategyTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->tenantContext->setTenant(
            (new Tenant())
                ->setName('Routing test')
                ->setSlug('routing-test'),
        );
        $this->transport('async')->reset();
        $this->transport('notifications')->reset();
        self::getContainer()->get(RoutingProbe::class)->reset();
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testYamlRouteIgnoresTenantMapAndDefaultTransport(): void
    {
        $envelope = $this->bus->dispatch(new SendNotificationMessage(1));

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    public function testAsMessageAttributeSelectsItsTransport(): void
    {
        $envelope = $this->bus->dispatch(new AttributeRoutedTenantMessage());

        self::assertCount(1, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    public function testConfiguredRouteTakesPriorityOverAttribute(): void
    {
        $this->bus->dispatch(new ConfiguredAndAttributedTenantMessage());

        self::assertCount(1, $this->transport('notifications')->getSent());
        self::assertCount(0, $this->transport('async')->getSent());
    }

    public function testExplicitTransportStampRemainsAuthoritativeAndIntact(): void
    {
        $stamp = new TransportNamesStamp(['async']);

        $this->bus->dispatch(new SendNotificationMessage(1), [$stamp]);

        self::assertCount(1, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertSame($stamp, $this->transport('async')->getSent()[0]->last(TransportNamesStamp::class));
    }

    public function testMessageWithoutRouteRunsSynchronouslyWithoutFallback(): void
    {
        $envelope = $this->bus->dispatch(new SynchronousTenantMessage());

        self::assertSame(1, self::getContainer()->get(RoutingProbe::class)->handledCount());
        self::assertCount(0, $this->transport('async')->getSent());
        self::assertCount(0, $this->transport('notifications')->getSent());
        self::assertNull($envelope->last(TransportNamesStamp::class));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
