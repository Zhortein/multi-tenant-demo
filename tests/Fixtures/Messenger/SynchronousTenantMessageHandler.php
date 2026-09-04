<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

use App\Tests\Fixtures\Message\SynchronousTenantMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SynchronousTenantMessageHandler
{
    public function __construct(
        private RoutingProbe $probe,
    ) {
    }

    public function __invoke(SynchronousTenantMessage $message): void
    {
        $this->probe->record();
    }
}
