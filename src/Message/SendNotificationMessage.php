<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for sending notifications asynchronously via Symfony Messenger.
 * 
 * This message demonstrates how to use Symfony Messenger in a multi-tenant
 * context, ensuring that messages are processed with the correct tenant
 * context and data isolation is maintained.
 */
final readonly class SendNotificationMessage
{
    public function __construct(
        private int $notificationId,
        private string $tenantSlug
    ) {
    }

    public function getNotificationId(): int
    {
        return $this->notificationId;
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}