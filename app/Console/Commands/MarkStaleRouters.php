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
        $routers = Router::withoutGlobalScopes()
            ->where('status', 'online')
            ->where(function ($query): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', now()->subMinutes(2));
            })
            ->get();

        foreach ($routers as $router) {
            $router->update(['status' => 'offline']);
        }

        $this->info('Marked '.$routers->count().' stale router(s) offline.');

        return self::SUCCESS;
    }
}
