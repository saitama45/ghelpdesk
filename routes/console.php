<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(InspiringQuote::quote());
})->purpose('Display an inspiring quote');

// The overlap mutex expires after 5 minutes instead of the 24-hour default: a run
// killed mid-flight (recycled container, PHP time limit) would otherwise leave the
// lock behind and stop ALL inbound mail for a day.
Schedule::command('tickets:fetch-emails')
    ->everyThirtySeconds()
    ->withoutOverlapping(5)
    ->runInBackground();
Schedule::command('tickets:auto-close')->hourly();
Schedule::command('tickets:process-scheduled')->everyMinute();
Schedule::command('presence:update-stale')->everyMinute();
Schedule::command('payments:send-due-reminders')->dailyAt('08:00');
Schedule::command('notifications:due-soon')->dailyAt('07:30');
