<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WalletLedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Proves that every wallet balance is explained by its ledger. A difference
 * means some code changed the balance without going through WalletService.
 */
class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Check that every tenant wallet balance equals the sum of its ledger';

    public function handle(): int
    {
        $drifted = 0;
        $checked = 0;

        Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use (&$drifted, &$checked): void {
            $sums = WalletLedgerEntry::query()
                ->whereIn('tenant_id', $tenants->modelKeys())
                ->selectRaw('tenant_id, SUM(amount) as total')
                ->groupBy('tenant_id')
                ->pluck('total', 'tenant_id');

            foreach ($tenants as $tenant) {
                $checked++;
                $ledger = round((float) ($sums[$tenant->id] ?? 0), 2);
                $balance = round((float) $tenant->wallet_balance, 2);

                if (abs($ledger - $balance) > 0.005) {
                    $drifted++;
                    $this->error("Tenant #{$tenant->id} ({$tenant->slug}): balance {$balance} but ledger sums to {$ledger}.");
                    Log::error('Wallet balance does not match its ledger.', [
                        'tenant_id' => $tenant->id,
                        'balance' => $balance,
                        'ledger' => $ledger,
                    ]);
                }
            }
        });

        $drifted === 0
            ? $this->info("All {$checked} wallet(s) match their ledger.")
            : $this->error("{$drifted} of {$checked} wallet(s) do not match their ledger.");

        return $drifted === 0 ? self::SUCCESS : self::FAILURE;
    }
}
