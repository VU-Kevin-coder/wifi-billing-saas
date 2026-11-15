<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Payments\PaymentGatewaySettings;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Console\Command;

class ReconcileWithdrawals extends Command
{
    protected $signature = 'withdrawals:reconcile {--minutes=10 : Minimum time since the withdrawal last changed}';

    protected $description = 'Reconcile pending withdrawals submitted to ObPay, PawaPay, or Xente';

    public function handle(PaymentPayoutService $withdrawals): int
    {
        $minutes = max(0, (int) ($this->option('minutes') ?? 10));
        $counts = [];

        Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->whereIn('meta->payout_gateway', PaymentGatewaySettings::PROVIDERS)
            ->whereIn('meta->payout_submission', ['submitting', 'unknown', 'submitted'])
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(50, function ($transactions) use ($withdrawals, &$counts): void {
                foreach ($transactions as $transaction) {
                    $state = $withdrawals->reconcile($transaction);
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                }
            });

        $this->info($counts
            ? 'Reconciled withdrawals: '.collect($counts)->map(fn ($n, $s) => "{$s}={$n}")->implode(', ')
            : 'No stuck withdrawals.');

        return (($counts['unavailable'] ?? 0) + ($counts['mismatch'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
