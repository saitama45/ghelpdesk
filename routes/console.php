<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(InspiringQuote::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:fetch-emails')->everyThirtySeconds();
Schedule::command('tickets:auto-close')->hourly();
