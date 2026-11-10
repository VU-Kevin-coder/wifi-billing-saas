<?php

namespace App\Console\Commands;

use App\Services\ExpiryReminderService;
use Illuminate\Console\Command;

class SendExpiryReminders extends Command
{
    protected $signature = 'sessions:remind';

    protected $description = 'SMS customers whose package is about to expire';

    public function handle(ExpiryReminderService $reminders): int
    {
        $counts = $reminders->run();

        $this->info('Expiry reminders: '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));

        return self::SUCCESS;
    }
}
