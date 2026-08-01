<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Requests asynchronous processing of one notification.
 *
 * Tenant identity is carried exclusively by the bundle's TenantStamp.
 */
final readonly class SendNotificationMessage
{
    public function __construct(
        private int $notificationId,
    ) {
    }

    public function getNotificationId(): int
    {
        return $this->notificationId;
    }
}
