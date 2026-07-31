<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Application-level tenant boundary for tenant-facing product queries.
 *
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> */
    public function findActiveForTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant, 'active' => true], ['createdAt' => 'DESC']);
    }

    public function findOneForTenant(int $id, Tenant $tenant): ?Product
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    public function findOneBySkuForTenant(string $sku, Tenant $tenant): ?Product
    {
        return $this->findOneBy(['sku' => $sku, 'tenant' => $tenant]);
    }
}
