<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;

final readonly class ReadStoredObjectMessage implements TenantAwareMessageInterface
{
    public function __construct(public StoredObjectReference $reference, public bool $failAfterRead = false)
    {
    }
}
