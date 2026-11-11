<?php

namespace App\Console\Commands;

use App\Models\Router;
use Illuminate\Console\Command;

class MarkStaleRouters extends Command
{
    protected $signature = 'routers:mark-stale';

    protected $description = 'Mark routers offline after their last successful health check becomes stale';

    public function handle(): int
    {
        $count = Router::withoutGlobalScopes()
            ->where('status', 'online')
            ->where(function ($query): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', now()->subMinutes(2));
            })
            ->update(['status' => 'offline']);

        $this->info("Marked {$count} stale router(s) offline.");

        return self::SUCCESS;
    }
}
