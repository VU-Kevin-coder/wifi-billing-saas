<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sessions:expire')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('payments:reconcile')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('withdrawals:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
