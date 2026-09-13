<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat for the admin "Cron Jobs" settings page: proves the server cron is
// actually firing schedule:run. Read by CronApiController.
Schedule::call(function () {
    Cache::put('cron_last_heartbeat', now()->toDateTimeString(), 172800);
})->everyMinute()->name('cron-heartbeat');

Schedule::command('cart:notification')->everyMinute();

// Applies scheduled maintenance windows (flips *_mode on/off at start/end).
Schedule::command('maintenance:apply')->everyMinute();

// Auto-publishes home layout drafts at their scheduled time.
Schedule::command('home-layout:publish-scheduled')->everyMinute();

Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();
