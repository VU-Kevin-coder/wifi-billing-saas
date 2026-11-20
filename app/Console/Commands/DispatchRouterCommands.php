<?php

namespace App\Console\Commands;

use App\Services\RouterCommandService;
use Illuminate\Console\Command;

class DispatchRouterCommands extends Command
{
    protected $signature = 'routers:dispatch-commands';

    protected $description = 'Deliver pending router commands over the verified WireGuard API-SSL path';

    public function handle(RouterCommandService $commands): int
    {
        $considered = $commands->dispatchPendingApiCommands();
        $this->info("Considered {$considered} pending router command(s).");

        return self::SUCCESS;
    }
}
