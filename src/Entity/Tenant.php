<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Tenant entity representing a multi-tenant organization.
 * 
 * This entity implements the TenantInterface required by the multi-tenant bundle
 * and contains all the necessary information to identify and manage tenants.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
#[UniqueEntity(fields: ['slug'], message: 'This slug is already in use.')]
#[UniqueEntity(fields: ['domain'], message: 'This domain is already in use.')]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Tenant name cannot be blank.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Tenant name must be at least {{ limit }} characters long.',
        maxMessage: 'Tenant name cannot be longer than {{ limit }} characters.'
    )]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    #[Assert\NotBlank(message: 'Tenant slug cannot be blank.')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9-]+$/',
        message: 'Slug can only contain lowercase letters, numbers, and hyphens.'
    )]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Slug must be at least {{ limit }} characters long.',
        maxMessage: 'Slug cannot be longer than {{ limit }} characters.'
    )]
    private string $slug = '';

    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: true)]
    #[Assert\Url(message: 'Please enter a valid domain.')]
    private ?string $domain = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: User::class)]
    private Collection $users;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Product::class)]
    private Collection $products;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Document::class)]
    private Collection $documents;

    /**
     * @var Collection<int, Notification>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Notification::class)]
    private Collection $notifications;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->notifications = new ArrayCollection();
    }

    public function getId(): string|int
    {
        return $this->id ?? 0;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): static
    {
        $this->domain = $domain;
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

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setTenant($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getTenant() === $this) {
                $user->setTenant(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setTenant($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            // set the owning side to null (unless already changed)
            if ($product->getTenant() === $this) {
                $product->setTenant(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setTenant($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getTenant() === $this) {
                $document->setTenant(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function addNotification(Notification $notification): static
    {
        if (!$this->notifications->contains($notification)) {
            $this->notifications->add($notification);
            $notification->setTenant($this);
        }

        return $this;
    }

    public function removeNotification(Notification $notification): static
    {
        if ($this->notifications->removeElement($notification)) {
            // set the owning side to null (unless already changed)
            if ($notification->getTenant() === $this) {
                $notification->setTenant(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    // TenantInterface implementation methods
    public function getMailerDsn(): ?string
    {
        // Return null to use default mailer configuration
        // This could be customized per tenant if needed
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        // Return null to use default messenger configuration
        // This could be customized per tenant if needed
        return null;
    }
}