<?php

use App\Services\Policy\Policy;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sessions:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Payment reconciler (default: every minute)
$paymentMinutes = max(1, (int) Policy::get('schedule.payment_reconcile_minutes', 1));
Schedule::command('payments:reconcile')
    ->cron("*/{$paymentMinutes} * * * *")
    ->withoutOverlapping();

// Provisioning watchdog (default: every minute)
$watchdogMinutes = max(1, (int) Policy::get('schedule.provisioning_watchdog_minutes', 1));
Schedule::command('provisioning:watchdog')
    ->cron("*/{$watchdogMinutes} * * * *")
    ->withoutOverlapping();

// Withdrawal reconciler (default: every 5 minutes)
$withdrawalMinutes = max(1, (int) Policy::get('schedule.withdrawal_reconcile_minutes', 5));
Schedule::command('withdrawals:reconcile')
    ->cron("*/{$withdrawalMinutes} * * * *")
    ->withoutOverlapping();
