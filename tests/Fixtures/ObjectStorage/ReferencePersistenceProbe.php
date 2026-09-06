<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use Doctrine\DBAL\Connection;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantStorageNamespaceResolverInterface;

/** PostgreSQL JSON persistence fixture, deliberately outside the application schema. */
final readonly class ReferencePersistenceProbe
{
    public function __construct(
        private Connection $connection,
        private TenantContextInterface $context,
        private TenantStorageNamespaceResolverInterface $namespaces,
    ) {
    }

    public function createTemporaryTable(): void
    {
        // Owned by this connection; PostgreSQL removes it when the connection closes.
        $this->connection->executeStatement('CREATE TEMPORARY TABLE demo_object_reference_probe (id VARCHAR(64) PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, reference JSONB NOT NULL)');
    }

    public function save(string $id, StoredObjectReference $reference): void
    {
        $tenant = $this->context->getTenant() ?? throw new \LogicException('Reference fixture requires a tenant.');
        if (!hash_equals($this->namespaces->resolve($tenant), $reference->tenantNamespace)) {
            throw new \LogicException('Reference fixture access denied.');
        }
        $this->connection->insert('demo_object_reference_probe', [
            'id' => $id, 'tenant_id' => (string) $tenant->getId(), 'reference' => $reference->toJson(),
        ]);
    }

    public function load(string $id): StoredObjectReference
    {
        $tenant = $this->context->getTenant() ?? throw new \LogicException('Reference fixture requires a tenant.');
        $json = $this->connection->fetchOne('SELECT reference FROM demo_object_reference_probe WHERE id = :id AND tenant_id = :tenant', [
            'id' => $id, 'tenant' => (string) $tenant->getId(),
        ]);
        if (!is_string($json)) {
            throw new \LogicException('Reference fixture access denied.');
        }
        $reference = StoredObjectReference::fromJson($json);
        if (!hash_equals($this->namespaces->resolve($tenant), $reference->tenantNamespace)) {
            throw new \LogicException('Reference fixture access denied.');
        }

        return $reference;
    }
}
