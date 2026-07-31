<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Zhortein\MultiTenantBundle\Attribute\TenantAware;

/**
 * Product entity with multi-tenant support.
 * 
 * This entity demonstrates how business data can be isolated per tenant.
 * Each product belongs to a specific tenant and can only be accessed
 * within that tenant's context.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[TenantAware(tenantFieldName: 'tenant')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Product name cannot be blank.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Product name must be at least {{ limit }} characters long.',
        maxMessage: 'Product name cannot be longer than {{ limit }} characters.'
    )]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Price cannot be blank.')]
    #[Assert\PositiveOrZero(message: 'Price must be positive or zero.')]
    private string $price = '0.00';

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'SKU cannot be blank.')]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'SKU must be at least {{ limit }} characters long.',
        maxMessage: 'SKU cannot be longer than {{ limit }} characters.'
    )]
    private string $sku = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'Stock quantity must be positive or zero.')]
    private int $stockQuantity = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Product must belong to a tenant.')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'createdProducts')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPriceAsFloat(): float
    {
        return (float) $this->price;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function setStockQuantity(int $stockQuantity): static
    {
        $this->stockQuantity = $stockQuantity;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isInStock(): bool
    {
        return $this->stockQuantity > 0;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}