<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

final readonly class SynchronousTenantMessage implements TenantAwareMessageInterface
{
}
