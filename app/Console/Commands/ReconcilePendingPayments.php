<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile {--minutes=5 : Minimum age before a pending charge is checked}';

    protected $description = 'Reconcile pending mobile-money collections with their providers';

    public function handle(PaymentService $payments): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $checked = 0;
        $unavailable = 0;

        Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('status', 'pending')
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->whereIn('meta->payment_provider', ['MTN', 'AIRTEL'])
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use ($payments, &$checked, &$unavailable): void {
                foreach ($transactions as $transaction) {
                    $checked++;
                    $status = $payments->reconcilePendingPayment($transaction);
                    if ($status === 'unavailable') {
                        $unavailable++;
                    }
                }
            });

        if ($unavailable > 0) {
            Log::warning('Pending mobile-money reconciliation had provider errors.', [
                'checked' => $checked,
                'unavailable' => $unavailable,
            ]);
        }

        $this->info("Checked {$checked} pending payment(s); {$unavailable} provider check(s) were unavailable.");

        return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
    }
}
