<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

#[AsMessage('async')]
final readonly class ConfiguredAndAttributedTenantMessage implements TenantAwareMessageInterface
{
}
