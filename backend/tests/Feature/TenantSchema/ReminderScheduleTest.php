<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the 24h reminder command to run hourly', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn (Event $e) => str_contains($e->command ?? '', 'appointments:send-reminders'));

    expect($events)->toHaveCount(1);

    // "0 * * * *" is Laravel's cron expression for ->hourly().
    expect($events->first()->expression)->toBe('0 * * * *');
});
