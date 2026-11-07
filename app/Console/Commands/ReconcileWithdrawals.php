<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use Illuminate\Console\Command;

class ReconcileWithdrawals extends Command
{
    protected $signature = 'withdrawals:reconcile {--minutes=10 : Minimum time since the withdrawal last changed}';

    protected $description = 'Resolve withdrawals stuck after an uncertain or unfinished Flutterwave transfer';

    public function handle(FlutterwaveWithdrawalService $withdrawals): int
    {
        $minutes = max(0, (int) $this->option('minutes'));
        $counts = [];

        Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereIn('meta->flutterwave_transfer_submission', ['submitting', 'unknown'])
                    ->orWhereNotNull('meta->flutterwave_transfer_id');
            })
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(50, function ($transactions) use ($withdrawals, &$counts): void {
                foreach ($transactions as $transaction) {
                    $state = $withdrawals->reconcileStuck($transaction)['state'];
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                }
            });

        $this->info($counts
            ? 'Reconciled withdrawals: '.collect($counts)->map(fn ($n, $s) => "{$s}={$n}")->implode(', ')
            : 'No stuck withdrawals.');

        return (($counts['unavailable'] ?? 0) + ($counts['manual_review'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
