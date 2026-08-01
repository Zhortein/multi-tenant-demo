<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Tenant;
use App\Entity\User;
use App\Message\SendNotificationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Service for handling tenant-aware notifications and email sending.
 * 
 * This service demonstrates how to implement tenant-isolated notification
 * and email functionality, ensuring that notifications are properly scoped
 * to tenants and emails are sent with appropriate tenant context.
 */
final readonly class TenantNotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private TenantMailerService $tenantMailer,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create and optionally send a notification.
     */
    public function createNotification(
        string $title,
        string $message,
        string $type = Notification::TYPE_INFO,
        ?User $recipient = null,
        ?string $recipientEmail = null,
        bool $sendEmail = false,
        bool $sendInApp = true,
        bool $sendAsync = true
    ): Notification {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new \RuntimeException('No tenant context available for notification creation');
        }

        // Create notification entity
        $notification = new Notification();
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($type);
        $notification->setTenant($tenant);
        $notification->setRecipient($recipient);
        $notification->setRecipientEmail($recipientEmail ?? $recipient?->getEmail());
        $notification->setSendEmail($sendEmail);
        $notification->setSendInApp($sendInApp);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $this->logger->info('Notification created', [
            'notification_id' => $notification->getId(),
            'title' => $title,
            'type' => $type,
            'tenant_slug' => $tenant->getSlug(),
            'send_email' => $sendEmail,
            'send_async' => $sendAsync,
        ]);

        // Send notification (async or sync)
        if ($sendAsync) {
            $this->sendNotificationAsync($notification);
        } else {
            $this->processNotification($notification);
        }

        return $notification;
    }

    /**
     * Send notification asynchronously via Messenger.
     */
    public function sendNotificationAsync(Notification $notification): void
    {
        $message = new SendNotificationMessage(
            $notification->getId()
        );

        $this->messageBus->dispatch($message);

        $this->logger->info('Notification queued for async processing', [
            'notification_id' => $notification->getId(),
            'tenant_slug' => $notification->getTenant()->getSlug(),
        ]);
    }

    /**
     * Process a notification (send email if needed).
     */
    public function processNotification(Notification $notification): void
    {
        try {
            if ($notification->shouldSendEmail() && $notification->getRecipientEmail()) {
                $this->sendEmail($notification);
            }

            $notification->markAsSent();
            $this->entityManager->flush();

            $this->logger->info('Notification processed successfully', [
                'notification_id' => $notification->getId(),
                'tenant_slug' => $notification->getTenant()->getSlug(),
            ]);

        } catch (\Exception $e) {
            $notification->markAsFailed($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Failed to process notification', [
                'notification_id' => $notification->getId(),
                'tenant_slug' => $notification->getTenant()->getSlug(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send email for a notification using the tenant mailer service.
     */
    private function sendEmail(Notification $notification): void
    {
        $recipientEmail = $notification->getRecipientEmail();

        if (!$recipientEmail) {
            throw new \RuntimeException('No recipient email available for notification');
        }

        $this->tenantMailer->sendNotificationEmail(
            to: $recipientEmail,
            title: $notification->getTitle(),
            message: $notification->getMessage(),
            type: $notification->getType(),
            tenant: $notification->getTenant()
        );

        $this->logger->info('Email sent for notification', [
            'notification_id' => $notification->getId(),
            'recipient_email' => $recipientEmail,
            'tenant_slug' => $notification->getTenant()->getSlug(),
        ]);
    }

    /**
     * Get unread notifications for the current tenant.
     * 
     * @return Notification[]
     */
    public function getUnreadNotifications(?User $user = null, int $limit = 10): array
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $criteria = [
            'tenant' => $tenant,
            'sendInApp' => true,
            'isRead' => false,
        ];

        if ($user) {
            $criteria['recipient'] = $user;
        }

        return $this->entityManager->getRepository(Notification::class)
            ->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(Notification $notification): void
    {
        $notification->markAsRead();
        $this->entityManager->flush();

        $this->logger->info('Notification marked as read', [
            'notification_id' => $notification->getId(),
            'tenant_slug' => $notification->getTenant()->getSlug(),
        ]);
    }

    /**
     * Get notification statistics for the current tenant.
     * 
     * @return array{total: int, unread: int, sent: int, failed: int}
     */
    public function getNotificationStats(): array
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            return ['total' => 0, 'unread' => 0, 'sent' => 0, 'failed' => 0];
        }

        $repository = $this->entityManager->getRepository(Notification::class);

        return [
            'total' => $repository->count(['tenant' => $tenant]),
            'unread' => $repository->count(['tenant' => $tenant, 'isRead' => false, 'sendInApp' => true]),
            'sent' => $repository->count(['tenant' => $tenant, 'status' => Notification::STATUS_SENT]),
            'failed' => $repository->count(['tenant' => $tenant, 'status' => Notification::STATUS_FAILED]),
        ];
    }

    /**
     * Send a test notification to verify functionality.
     */
    public function sendTestNotification(
        ?User $recipient = null,
        ?string $recipientEmail = null,
        bool $sendEmail = false
    ): Notification {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        return $this->createNotification(
            title: 'Test Notification',
            message: 'This is a test notification to verify that the tenant-aware notification system is working correctly. ' .
                    'This notification was created for tenant "' . $tenant->getName() . '" at ' . 
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s') . '.',
            type: Notification::TYPE_INFO,
            recipient: $recipient,
            recipientEmail: $recipientEmail,
            sendEmail: $sendEmail,
            sendInApp: true,
            sendAsync: true
        );
    }
}