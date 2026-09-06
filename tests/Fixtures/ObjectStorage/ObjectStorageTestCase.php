<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use App\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantStorageNamespaceResolverInterface;

abstract class ObjectStorageTestCase extends KernelTestCase
{
    protected TenantContextInterface $context;
    protected TenantObjectStorageInterface $storage;
    protected ObjectStorageRegistry $registry;
    protected TenantStorageNamespaceResolverInterface $namespaces;
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    /** @var list<array{Tenant, StoredObjectReference}> */
    private array $owned = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->context = self::service(TenantContextInterface::class);
        $this->storage = self::service(TenantObjectStorageInterface::class);
        $this->registry = self::service(ObjectStorageRegistry::class);
        $this->namespaces = self::service(TenantStorageNamespaceResolverInterface::class);
        $repository = self::service(EntityManagerInterface::class)->getRepository(Tenant::class);
        $a = $repository->findOneBy(['slug' => 'tenant-a']);
        $b = $repository->findOneBy(['slug' => 'tenant-b']);
        self::assertTrue($a instanceof Tenant, 'Run the deterministic demo fixtures first.');
        self::assertTrue($b instanceof Tenant);
        $this->tenantA = $a;
        $this->tenantB = $b;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->owned as [$tenant, $reference]) {
                $this->context->setTenant($tenant);
                $this->storage->delete($reference);
            }
        } finally {
            $this->context->clear();
            parent::tearDown();
        }
    }

    protected function allocate(Tenant $tenant, ?TenantObjectStorageInterface $storage = null): StoredObjectReference
    {
        $this->context->setTenant($tenant);
        $reference = ($storage ?? $this->storage)->allocate();
        $this->track($tenant, $reference);

        return $reference;
    }

    protected function track(Tenant $tenant, StoredObjectReference $reference): void
    {
        $this->owned[] = [$tenant, $reference];
        // Boolean assertions prevent PHPUnit from dumping a full address on failure.
        self::assertTrue(1 === preg_match('/\A[0-9a-f]{64}\z/', $reference->key));
        foreach (['tenant-a', 'tenant-b', 'Tenant A', 'Tenant B', 'alice@', 'bob@', 'sensitive-original.txt'] as $sensitive) {
            self::assertFalse(str_contains($reference->toJson(), $sensitive), 'Technical references must contain no business data.');
        }
    }

    protected function facade(string $sharedGeneration = 'shared_v1', string $provider = 'shared', ?ObjectStorageRegistry $registry = null): TenantObjectStorageInterface
    {
        return new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector($provider), $this->namespaces,
            $registry ?? new ObjectStorageRegistry([
                $this->registry->location('shared_v1'), $this->registry->location('shared_v2'), $this->registry->location('dedicated_v1'),
            ], ['shared' => $sharedGeneration, 'dedicated' => 'dedicated_v1']), true, 10, 60);
    }

    protected function ioCalls(): int
    {
        $count = 0;
        foreach (['shared_v1', 'shared_v2', 'dedicated_v1'] as $id) {
            $backend = $this->registry->location($id)->backend;
            self::assertTrue($backend instanceof StorageIoProbe);
            $count += $backend->calls;
        }

        return $count;
    }

    /** @param \Closure(): mixed $operation */
    protected function rejected(ObjectStorageError $reason, \Closure $operation, bool $beforeIo = true): void
    {
        $calls = $this->ioCalls();
        try {
            $operation();
            self::fail('Object operation must be rejected.');
        } catch (ObjectStorageException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertNull($exception->getPrevious());
            self::assertSame('Object storage: '.$reason->value.'.', $exception->getMessage());
        }
        if ($beforeIo) {
            self::assertSame($calls, $this->ioCalls(), 'Rejection must precede every adapter call.');
        }
    }

    protected function persistence(bool $create = true): ReferencePersistenceProbe
    {
        $probe = new ReferencePersistenceProbe(self::service(Connection::class), $this->context, $this->namespaces);
        if ($create) {
            $probe->createTemporaryTable();
        }

        return $probe;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertTrue($service instanceof $class);

        return $service;
    }
}
