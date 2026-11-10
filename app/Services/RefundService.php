<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalance;
use App\Exceptions\RefundException;
use App\Models\AuditLog;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\RefundRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refunds of completed voucher sales.
 *
 * The operator asks; a platform administrator (who controls the payment
 * provider account) refunds the customer and records the payout reference,
 * which approves the request. Approval takes the operator's share back out of
 * their wallet (through the ledger) and reverses the platform's commission on
 * the refunded part. Customers are never paid automatically by this code.
 */
class RefundService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly AccessRevoker $revoker,
    ) {}

    /** What can still be refunded on a sale (pending requests are reserved). */
    public function refundable(Transaction $sale): float
    {
        $reserved = (float) Refund::withoutTenancy()
            ->where('transaction_id', $sale->id)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        return round((float) $sale->amount - $reserved, 2);
    }

    public function request(Transaction $sale, float $amount, string $reason, ?User $by, bool $revokeAccess = true): Refund
    {
        $refund = DB::transaction(function () use ($sale, $amount, $reason, $by, $revokeAccess): Refund {
            $locked = Transaction::withoutTenancy()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->type !== 'voucher_sale' || $locked->status !== 'completed') {
                throw new RefundException('Only completed sales can be refunded.');
            }

            $window = (int) config('refunds.window_days', 90);
            if ($window > 0 && $locked->created_at->lt(now()->subDays($window))) {
                throw new RefundException("Sales older than {$window} days cannot be refunded here.");
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new RefundException('The refund amount must be greater than zero.');
            }

            $refundable = $this->refundable($locked);
            if ($amount > $refundable + 0.004) {
                throw new RefundException('Only '.number_format($refundable, 2).' can still be refunded for this sale.');
            }

            $refund = Refund::create([
                'tenant_id' => $locked->tenant_id,
                'transaction_id' => $locked->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'pending',
                'revoke_access' => $revokeAccess,
                'requested_by' => $by?->id,
            ]);

            AuditLog::record('refund.requested', $refund, [
                'sale_reference' => $locked->reference,
                'amount' => $amount,
                'reason' => $reason,
            ], $by);

            return $refund;
        });

        $this->notifyPlatform($refund);

        return $refund;
    }

    /**
     * @throws RefundException
     */
    public function approve(Refund $refund, User $by, string $payoutReference): Refund
    {
        $approved = DB::transaction(function () use ($refund, $by, $payoutReference): Refund {
            // Lock order everywhere: refund -> sale -> tenant wallet.
            $locked = Refund::withoutTenancy()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'pending') {
                throw new RefundException('This refund request has already been decided.');
            }

            $sale = Transaction::withoutTenancy()->lockForUpdate()->findOrFail($locked->transaction_id);

            $gross = (float) $sale->amount;
            $net = (float) $sale->net_amount;
            $amount = (float) $locked->amount;

            // The operator only received `net` of the sale; take back their share
            // of the refunded part and reverse the platform commission on the rest.
            $tenantDebit = $gross > 0 ? round($amount * ($net / $gross), 2) : 0.0;
            $feeReversed = round($amount - $tenantDebit, 2);

            $refundTransaction = Transaction::withoutTenancy()->create([
                'tenant_id' => $locked->tenant_id,
                'type' => 'refund',
                'amount' => $amount,
                'platform_fee' => -1 * $feeReversed,
                'net_amount' => -1 * $tenantDebit,
                'status' => 'completed',
                'reference' => 'REF-'.strtoupper(Str::random(8)),
                'phone' => $sale->phone,
                'description' => "Refund of sale {$sale->reference}",
                'meta' => ['original_transaction_id' => $sale->id, 'refund_id' => $locked->id],
            ]);

            try {
                $entry = $this->wallet->apply(
                    $locked->tenant_id,
                    -1 * $tenantDebit,
                    WalletService::REFUND_DEBIT,
                    $refundTransaction,
                    "Refund of sale {$sale->reference}",
                    null,
                    $by->id,
                    ['refund_id' => $locked->id, 'sale_transaction_id' => $sale->id],
                );
            } catch (InsufficientWalletBalance $e) {
                throw new RefundException(
                    "The operator's wallet balance (".number_format($e->balance, 2).') is too low to cover this refund ('
                    .number_format($e->required, 2).'). Reject the request, or ask the operator to resolve it first.'
                );
            }

            $locked->update([
                'status' => 'approved',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'payout_reference' => $payoutReference,
                'tenant_debit' => $tenantDebit,
                'platform_fee_reversed' => $feeReversed,
                'refund_transaction_id' => $refundTransaction->id,
                'wallet_ledger_entry_id' => $entry->id,
            ]);

            AuditLog::record('refund.approved', $locked, [
                'sale_reference' => $sale->reference,
                'refund_reference' => $refundTransaction->reference,
                'amount' => $amount,
                'tenant_debit' => $tenantDebit,
                'platform_fee_reversed' => $feeReversed,
                'payout_reference' => $payoutReference,
            ], $by);

            return $locked;
        });

        $sale = Transaction::withoutTenancy()->find($approved->transaction_id);
        if ($approved->revoke_access && $sale && $this->fullyRefunded($sale)) {
            $this->revokeAccess($sale);
        }

        return $approved;
    }

    public function reject(Refund $refund, User $by, ?string $note = null): Refund
    {
        return $this->close($refund, $by, 'rejected', $note, 'refund.rejected');
    }

    public function cancel(Refund $refund, User $by): Refund
    {
        return $this->close($refund, $by, 'cancelled', null, 'refund.cancelled');
    }

    private function close(Refund $refund, User $by, string $status, ?string $note, string $audit): Refund
    {
        return DB::transaction(function () use ($refund, $by, $status, $note, $audit): Refund {
            $locked = Refund::withoutTenancy()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'pending') {
                throw new RefundException('This refund request has already been decided.');
            }

            $locked->update([
                'status' => $status,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            AuditLog::record($audit, $locked, ['amount' => (float) $locked->amount, 'note' => $note], $by);

            return $locked;
        });
    }

    private function fullyRefunded(Transaction $sale): bool
    {
        $approved = (float) Refund::withoutTenancy()
            ->where('transaction_id', $sale->id)
            ->where('status', 'approved')
            ->sum('amount');

        return $approved + 0.004 >= (float) $sale->amount;
    }

    /**
     * After a full refund the customer must not keep the service. Best effort:
     * the refund itself has already been recorded.
     */
    private function revokeAccess(Transaction $sale): void
    {
        try {
            $voucherId = $sale->meta['voucher_id'] ?? null;
            $voucher = $voucherId ? Voucher::withoutTenancy()->where('tenant_id', $sale->tenant_id)->find($voucherId) : null;

            if ($voucher) {
                $this->revoker->revoke($voucher, 'refund of sale '.$sale->reference);
            }
        } catch (Throwable $exception) {
            Log::error('Unable to revoke access after a refund.', [
                'sale_transaction_id' => $sale->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function notifyPlatform(Refund $refund): void
    {
        try {
            $admins = User::withoutTenancy()->role('platform_admin')->get();
            if ($admins->isEmpty()) {
                return;
            }

            $sale = Transaction::withoutTenancy()->find($refund->transaction_id);
            $tenant = Tenant::find($refund->tenant_id);

            $url = null;
            try {
                $url = rtrim((string) config('app.url'), '/').route('platform.refunds.index', [], false);
            } catch (Throwable) {
            }

            Notification::send($admins, new RefundRequested(
                $refund->id,
                $tenant?->name ?? 'An operator',
                number_format((float) $refund->amount, 2),
                $sale?->reference ?? '#'.$refund->transaction_id,
                $refund->reason,
                $url,
                (int) $refund->tenant_id,
            ));
        } catch (Throwable $exception) {
            Log::error('Unable to notify platform admins of a refund request.', ['exception' => $exception::class]);
        }
    }
}
