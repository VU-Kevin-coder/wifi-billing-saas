<?php

namespace App\Console\Commands;

use App\Services\Policy\AdjustmentService;
use Illuminate\Console\Command;

class ExpireAdjustments extends Command
{
    protected $signature = 'adjustments:expire';

    protected $description = 'Expire pending wallet adjustments past their expiry window';

    public function handle(AdjustmentService $service): int
    {
        $count = $service->expireStale();
        $this->info("Expired {$count} pending adjustment(s).");

        return self::SUCCESS;
    }
}
