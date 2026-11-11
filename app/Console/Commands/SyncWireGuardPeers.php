<?php

namespace App\Console\Commands;

use App\Services\WireGuardService;
use Illuminate\Console\Command;

class SyncWireGuardPeers extends Command
{
    protected $signature = 'wireguard:sync-peers';

    protected $description = 'Restore enabled WireGuard peers to the configured interface';

    public function handle(WireGuardService $wireGuard): int
    {
        $count = $wireGuard->syncPeers();
        $this->info("Restored {$count} WireGuard peer(s).");

        return self::SUCCESS;
    }
}
