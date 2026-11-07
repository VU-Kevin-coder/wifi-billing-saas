<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\ProvisioningWatchdog;
use Illuminate\Console\Command;

class WatchProvisioning extends Command
{
    protected $signature = 'provisioning:watchdog';

    protected $description = 'Retry, alert about and close paid sessions stuck waiting for router activation';

    public function handle(ProvisioningWatchdog $watchdog): int
    {
        $counts = [];

        Session::withoutTenancy()
            ->where('status', 'provisioning')
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($watchdog, &$counts): void {
                foreach ($sessions as $session) {
                    $outcome = $watchdog->inspect($session);
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }
            });

        $this->info($counts
            ? 'Provisioning watchdog: '.collect($counts)->map(fn ($n, $o) => "{$o}={$n}")->implode(', ')
            : 'No sessions waiting for activation.');

        return self::SUCCESS;
    }
}
