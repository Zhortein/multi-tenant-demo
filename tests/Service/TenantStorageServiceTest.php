<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Service\TenantStorageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

final class TenantStorageServiceTest extends TestCase
{
    public function testCrossTenantFileAccessIsRejectedBeforeStorage(): void
    {
        $tenantA = (new Tenant())->setSlug('tenant-a');
        $tenantB = (new Tenant())->setSlug('tenant-b');
        $document = (new Document())->setTenant($tenantB)->setFilePath('documents/file.txt');
        $context = $this->createStub(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenantA);
        $storage = $this->createMock(TenantFileStorageInterface::class);
        $storage->expects(self::never())->method('exists');

        $service = new TenantStorageService(
            $this->createStub(EntityManagerInterface::class),
            $context,
            $storage,
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(TenantStorageException::class);
        $service->fileExists($document);
    }

    public function testMissingTenantContextFailsClosed(): void
    {
        $context = $this->createStub(TenantContextInterface::class);
        $context->method('getTenant')->willReturn(null);
        $service = new TenantStorageService(
            $this->createStub(EntityManagerInterface::class),
            $context,
            $this->createStub(TenantFileStorageInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(TenantStorageException::class);
        $service->getStorageStats();
    }

    public function testSameTenantUsesTheBundleStoragePath(): void
    {
        $tenant = (new Tenant())->setSlug('default');
        $document = (new Document())->setTenant($tenant)->setFilePath('documents/file.txt');
        $context = $this->createStub(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $storage = $this->createMock(TenantFileStorageInterface::class);
        $storage->expects(self::once())
            ->method('getPath')
            ->with('documents/file.txt')
            ->willReturn('/app/var/uploads/tenants/default/documents/file.txt');

        $service = new TenantStorageService(
            $this->createStub(EntityManagerInterface::class),
            $context,
            $storage,
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame(
            '/app/var/uploads/tenants/default/documents/file.txt',
            $service->getFilePath($document),
        );
    }
}
