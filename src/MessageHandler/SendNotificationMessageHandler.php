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
 * Processes a notification only inside the context restored from TenantStamp.
 */
#[AsMessageHandler]
final readonly class SendNotificationMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private TenantNotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            $this->logger->error('Notification processing requires a restored tenant context.', [
                'notification_id' => $message->getNotificationId(),
            ]);

            throw new \RuntimeException('Notification processing requires a restored tenant context.');
        }

        $notification = $this->entityManager->getRepository(Notification::class)
            ->findOneBy(['id' => $message->getNotificationId(), 'tenant' => $tenant]);

        if (!$notification instanceof Notification) {
            $this->logger->warning('Notification not found in the restored tenant context.', [
                'notification_id' => $message->getNotificationId(),
                'tenant_slug' => $tenant->getSlug(),
            ]);

            throw new \RuntimeException('Notification does not belong to the restored tenant context.');
        }

        try {
            $this->notificationService->processNotification($notification);
        } catch (\Throwable $exception) {
            $notification->markAsFailed($exception->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Notification processing failed.', [
                'notification_id' => $message->getNotificationId(),
                'tenant_slug' => $tenant->getSlug(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
