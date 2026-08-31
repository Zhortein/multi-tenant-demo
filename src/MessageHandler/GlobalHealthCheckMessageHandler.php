<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GlobalHealthCheckMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GlobalHealthCheckMessageHandler
{
    public function __invoke(GlobalHealthCheckMessage $message): void
    {
    }
}
