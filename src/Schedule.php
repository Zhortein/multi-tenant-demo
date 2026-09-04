<?php

declare(strict_types=1);

namespace App;

use App\Message\GlobalHealthCheckMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('health_check')]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())->add(
            RecurringMessage::every(
                3600,
                new RedispatchMessage(new GlobalHealthCheckMessage(), 'scheduler_persistent'),
            ),
        );
    }
}
