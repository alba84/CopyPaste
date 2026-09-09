<?php

namespace App;

use Cron\CronExpression;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

#[AsSchedule('default')]
final class Schedule implements ScheduleProviderInterface
{
    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())->add(RecurringMessage::trigger(new CronExpressionTrigger(new CronExpression('0 * * * *')), new RunCommandMessage('app:paste:purge')));
    }
}
