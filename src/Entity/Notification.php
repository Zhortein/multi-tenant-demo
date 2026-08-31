<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

/**
 * Notification entity for testing tenant-aware messaging and email functionality.
 * 
 * This entity demonstrates how notifications, emails, and messages work
 * within a multi-tenant context, ensuring communications are isolated
 * per tenant and cannot be accessed across tenant boundaries.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[AsTenantAware(tenantField: 'tenant')]
class Notification
{
    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $type = self::TYPE_INFO;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $sendEmail = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $sendInApp = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isRead = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!in_array($type, [self::TYPE_INFO, self::TYPE_SUCCESS, self::TYPE_WARNING, self::TYPE_ERROR], true)) {
            throw new \InvalidArgumentException('Invalid notification type');
        }

        $this->type = $type;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException('Invalid notification status');
        }

        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): static
    {
        $this->recipientEmail = $recipientEmail;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSendEmail(): bool
    {
        return $this->sendEmail;
    }

    public function shouldSendEmail(): bool
    {
        return $this->sendEmail;
    }

    public function setSendEmail(bool $sendEmail): static
    {
        $this->sendEmail = $sendEmail;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSendInApp(): bool
    {
        return $this->sendInApp;
    }

    public function shouldSendInApp(): bool
    {
        return $this->sendInApp;
    }

    public function setSendInApp(bool $sendInApp): static
    {
        $this->sendInApp = $sendInApp;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getIsRead(): bool
    {
        return $this->isRead;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
        if ($isRead && $this->readAt === null) {
            $this->readAt = new \DateTimeImmutable();
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
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

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(?User $recipient): static
    {
        $this->recipient = $recipient;
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

    /**
     * Get available notification types.
     * 
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_INFO => 'Info',
            self::TYPE_SUCCESS => 'Success',
            self::TYPE_WARNING => 'Warning',
            self::TYPE_ERROR => 'Error',
        ];
    }

    /**
     * Get available notification statuses.
     * 
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    /**
     * Get Bootstrap CSS class for the notification type.
     */
    public function getBootstrapClass(): string
    {
        return match ($this->type) {
            self::TYPE_SUCCESS => 'success',
            self::TYPE_WARNING => 'warning',
            self::TYPE_ERROR => 'danger',
            default => 'info',
        };
    }

    /**
     * Get Bootstrap icon for the notification type.
     */
    public function getBootstrapIcon(): string
    {
        return match ($this->type) {
            self::TYPE_SUCCESS => 'bi-check-circle',
            self::TYPE_WARNING => 'bi-exclamation-triangle',
            self::TYPE_ERROR => 'bi-x-circle',
            default => 'bi-info-circle',
        };
    }

    /**
     * Mark notification as sent.
     */
    public function markAsSent(): static
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark notification as failed.
     */
    public function markAsFailed(string $errorMessage): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(): static
    {
        $this->isRead = true;
        $this->readAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
