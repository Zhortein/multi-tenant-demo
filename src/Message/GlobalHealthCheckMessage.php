<?php

declare(strict_types=1);

namespace App\Message;

use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;

final readonly class GlobalHealthCheckMessage implements GlobalMessageInterface
{
}
