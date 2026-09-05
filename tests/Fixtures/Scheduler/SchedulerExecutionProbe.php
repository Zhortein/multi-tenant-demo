<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduler;

final class SchedulerExecutionProbe
{
    private int $handledCount = 0;
    public bool $failOnHandle = false;

    public function record(): void
    {
        ++$this->handledCount;
        if ($this->failOnHandle) {
            throw new \RuntimeException('Controlled Scheduler application-handler failure.');
        }
    }

    public function handledCount(): int
    {
        return $this->handledCount;
    }
}
