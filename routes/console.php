<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon metrics (dashboard graphs). The outbox relay is scheduled by the
// Platform module itself (PlatformServiceProvider).
Schedule::command('horizon:snapshot')->everyFiveMinutes();
