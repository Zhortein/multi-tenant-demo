<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduler;

final class SchedulerExecutionProbe
{
    private int $handledCount = 0;

    public function record(): void
    {
        ++$this->handledCount;
    }

    public function handledCount(): int
    {
        return $this->handledCount;
    }
}
