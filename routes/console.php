<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule reminder checks every minute
Schedule::command('reminders:send')->everyMinute();

// Prune old Telescope entries daily
Schedule::command('telescope:prune')->daily();
Schedule::command('suppliers:check-limit')->everyTenMinutes();
