<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Entity\Tenant;
use App\Message\SendNotificationMessage;
use App\Service\TenantNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Handler for processing notification messages asynchronously.
 * 
 * This handler demonstrates how to process messages in a multi-tenant
 * context, ensuring that the correct tenant context is set and
 * data isolation is maintained during message processing.
 */
#[AsMessageHandler]
final readonly class SendNotificationMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private TenantNotificationService $notificationService,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        try {
            // Find and set the tenant context for this message processing
            $tenant = $this->entityManager->getRepository(Tenant::class)
                ->findOneBy(['slug' => $message->getTenantSlug(), 'active' => true]);

            if (!$tenant) {
                $this->logger->error('Tenant not found for message processing', [
                    'tenant_slug' => $message->getTenantSlug(),
                    'notification_id' => $message->getNotificationId(),
                ]);
                return;
            }

            // Set the tenant context
            $this->tenantContext->setTenant($tenant);

            $this->logger->info('Processing notification message', [
                'notification_id' => $message->getNotificationId(),
                'tenant_slug' => $message->getTenantSlug(),
                'tenant_id' => $tenant->getId(),
            ]);

            // Find the notification (will be automatically filtered by tenant)
            $notification = $this->entityManager->getRepository(Notification::class)
                ->find($message->getNotificationId());

            if (!$notification) {
                $this->logger->warning('Notification not found', [
                    'notification_id' => $message->getNotificationId(),
                    'tenant_slug' => $message->getTenantSlug(),
                ]);
                return;
            }

            // Process the notification
            $this->notificationService->processNotification($notification);

            $this->logger->info('Notification processed successfully', [
                'notification_id' => $message->getNotificationId(),
                'tenant_slug' => $message->getTenantSlug(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process notification message', [
                'notification_id' => $message->getNotificationId(),
                'tenant_slug' => $message->getTenantSlug(),
                'error' => $e->getMessage(),
            ]);

            // Try to mark notification as failed if it exists
            try {
                // Ensure tenant context is set for error handling
                if (!$this->tenantContext->hasTenant()) {
                    $tenant = $this->entityManager->getRepository(Tenant::class)
                        ->findOneBy(['slug' => $message->getTenantSlug(), 'active' => true]);
                    if ($tenant) {
                        $this->tenantContext->setTenant($tenant);
                    }
                }

                $notification = $this->entityManager->getRepository(Notification::class)
                    ->find($message->getNotificationId());
                
                if ($notification) {
                    $notification->markAsFailed($e->getMessage());
                    $this->entityManager->flush();
                }
            } catch (\Exception $markFailedException) {
                $this->logger->error('Failed to mark notification as failed', [
                    'notification_id' => $message->getNotificationId(),
                    'tenant_slug' => $message->getTenantSlug(),
                    'original_error' => $e->getMessage(),
                    'mark_failed_error' => $markFailedException->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}