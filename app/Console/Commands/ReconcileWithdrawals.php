<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Console\Command;

class ReconcileWithdrawals extends Command
{
    protected $signature = 'withdrawals:reconcile {--minutes=10 : Minimum time since the withdrawal last changed}';

    protected $description = 'Resolve withdrawals stuck after an uncertain or unfinished payout';

    public function handle(PaymentPayoutService $payouts, FlutterwaveWithdrawalService $legacyWithdrawals): int
    {
        $minutes = max(0, (int) ($this->option('minutes') ?? 10));
        $counts = [];

        Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNotNull('meta->payout_gateway')
                    ->where(function ($query): void {
                        $query->whereIn('meta->payout_submission', ['submitting', 'unknown', 'submitted'])
                            ->orWhereNotNull('meta->payout_provider_transaction_id');
                    });
            })
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(50, function ($transactions) use ($payouts, &$counts): void {
                foreach ($transactions as $transaction) {
                    $state = $payouts->reconcile($transaction);
                    $state = in_array($state, ['mismatch', 'fulfillment_data_missing'], true) ? 'manual_review' : $state;
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                }
            });

        Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereIn('meta->flutterwave_transfer_submission', ['submitting', 'unknown'])
                    ->orWhereNotNull('meta->flutterwave_transfer_id');
            })
            ->whereNull('meta->payout_gateway')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(50, function ($transactions) use ($legacyWithdrawals, &$counts): void {
                foreach ($transactions as $transaction) {
                    $state = $legacyWithdrawals->reconcileStuck($transaction)['state'];
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                }
            });

        $this->info($counts
            ? 'Reconciled withdrawals: '.collect($counts)->map(fn ($n, $s) => "{$s}={$n}")->implode(', ')
            : 'No stuck withdrawals.');

        return (($counts['unavailable'] ?? 0) + ($counts['manual_review'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
