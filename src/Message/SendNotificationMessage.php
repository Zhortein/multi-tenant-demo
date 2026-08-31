<?php

declare(strict_types=1);

namespace App\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

/**
 * Requests asynchronous processing of one notification.
 *
 * Tenant identity is carried exclusively by the bundle's TenantStamp.
 */
final readonly class SendNotificationMessage implements TenantAwareMessageInterface
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
