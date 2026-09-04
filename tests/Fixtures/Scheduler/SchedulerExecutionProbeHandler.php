<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduler;

use App\Message\GlobalHealthCheckMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SchedulerExecutionProbeHandler
{
    public function __construct(
        private SchedulerExecutionProbe $probe,
    ) {
    }

    public function __invoke(GlobalHealthCheckMessage $message): void
    {
        $this->probe->record();
    }
}
