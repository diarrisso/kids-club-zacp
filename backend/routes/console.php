<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 24h appointment reminders: hourly scan. The [24h,25h) window + hourly
// cadence means each appointment is reminded exactly once.
Schedule::command('appointments:send-reminders')->hourly()->withoutOverlapping();
