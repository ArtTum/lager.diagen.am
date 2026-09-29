<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('services.admin_sync.enabled')) {
    Schedule::command('admins:sync '.config('services.admin_sync.vendor').' --force')
        ->everyMinute()
        ->withoutOverlapping(5);
}
