<?php

namespace App\Services\Policy;

use App\Models\AuditLog;
use App\Models\PendingAdjustment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdjustmentService
{
    /**
     * @return array{status: 'auto_applied'|'pending'|'blocked', adjustment: ?PendingAdjustment, transaction: ?Transaction, message: string}
     */
    public function request(Tenant $tenant, float $amount, string $reason, User $requester): array
    {
        $max = (float) Policy::get('wallet.max_adjustment', 1000000);
        if (abs($amount) > $max) {
            return ['status' => 'blocked', 'adjustment' => null, 'transaction' => null,
                    'message' => "Amount exceeds the maximum single adjustment ({$max})."];
        }

        if ($amount < 0 && ((float) $tenant->wallet_balance + $amount) < 0) {
            throw new \DomainException('Adjustment would make the wallet balance negative.');
        }

        $decision = $this->decision($amount);

        if ($decision === 'blocked') {
            return ['status' => 'blocked', 'adjustment' => null, 'transaction' => null,
                    'message' => 'Wallet adjustments are currently disabled by policy.'];
        }

        if ($decision === 'auto') {
            $transaction = $this->apply($tenant, $amount, $reason, $requester, null);
            return ['status' => 'auto_applied', 'adjustment' => null, 'transaction' => $transaction,
                    'message' => 'Adjustment applied.'];
        }

        // Manual approval required
        $expiryHours = (int) Policy::get('approval.wallet_adjustment.expiry_hours', 24);

        $adjustment = PendingAdjustment::create([
            'tenant_id' => $tenant->id,
            'requested_by' => $requester->id,
            'amount' => round($amount, 2),
            'reason' => $reason,
            'status' => 'pending',
            'expires_at' => now()->addHours($expiryHours),
        ]);

        AuditLog::record('adjustment.requested', $adjustment, [
            'amount' => (float) $amount,
            'reason' => $reason,
        ], $requester, $tenant);

        return ['status' => 'pending', 'adjustment' => $adjustment, 'transaction' => null,
                'message' => 'Adjustment submitted. A second platform admin must approve it.'];
    }

    public function approve(PendingAdjustment $adjustment, User $approver, ?string $note = null): array
    {
        if (! $adjustment->isPending()) {
            return ['ok' => false, 'message' => 'This adjustment is no longer pending.'];
        }

        if (! AdjustmentApprovalPolicy::canApprove($approver, $adjustment->requested_by)) {
            return ['ok' => false, 'message' => 'You are not permitted to approve this adjustment.'];
        }

        return DB::transaction(function () use ($adjustment, $approver, $note) {
            $locked = PendingAdjustment::withoutTenancy()->lockForUpdate()->findOrFail($adjustment->id);
            if (! $locked->isPending()) {
                return ['ok' => false, 'message' => 'This adjustment is no longer pending.'];
            }

            $tenant = Tenant::findOrFail($locked->tenant_id);
            $transaction = $this->apply($tenant, (float) $locked->amount, $locked->reason, $approver, $locked);

            $locked->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'decided_at' => now(),
                'decision_reason' => $note,
                'transaction_id' => $transaction->id,
            ]);

            AuditLog::record('adjustment.approved', $locked, [
                'amount' => (float) $locked->amount,
                'approved_by' => $approver->id,
            ], $approver, $tenant);

            return ['ok' => true, 'message' => 'Adjustment approved and applied.', 'transaction' => $transaction];
        });
    }

    public function reject(PendingAdjustment $adjustment, User $approver, ?string $note = null): array
    {
        if (! $adjustment->isPending()) {
            return ['ok' => false, 'message' => 'This adjustment is no longer pending.'];
        }

        $adjustment->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'decided_at' => now(),
            'decision_reason' => $note,
        ]);

        AuditLog::record('adjustment.rejected', $adjustment, [
            'reason' => $note,
        ], $approver);

        return ['ok' => true, 'message' => 'Adjustment rejected.'];
    }

    public function expireStale(): int
    {
        $count = PendingAdjustment::withoutTenancy()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'decided_at' => now()]);

        if ($count > 0) {
            AuditLog::record('adjustment.expired', null, ['count' => $count]);
        }

        return $count;
    }

    private function decision(float $amount): string
    {
        if (! Policy::get('approval.wallet_adjustment.enabled', false)) {
            return 'auto';
        }

        $mode = Policy::get('approval.wallet_adjustment.mode', 'always');
        $threshold = (float) Policy::get('approval.wallet_adjustment.threshold', 0);

        return match ($mode) {
            'always' => 'pending',
            'threshold' => abs($amount) >= $threshold ? 'pending' : 'auto',
            'never' => 'auto',
            default => 'pending',
        };
    }

    private function apply(Tenant $tenant, float $amount, string $reason, User $applier, ?PendingAdjustment $source): Transaction
    {
        return DB::transaction(function () use ($tenant, $amount, $reason, $applier, $source) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $before = (float) $locked->wallet_balance;

            if ($before + $amount < 0) {
                throw new \DomainException('Adjustment would make the wallet balance negative.');
            }

            $reference = 'ADJ-' . strtoupper(Str::random(10));

            $transaction = Transaction::withoutTenancy()->create([
                'tenant_id' => $locked->id,
                'type' => 'adjustment',
                'amount' => abs($amount),
                'platform_fee' => 0,
                'net_amount' => round($amount, 2),
                'status' => 'completed',
                'description' => $reason,
                'reference' => $reference,
            ]);

            $locked->increment('wallet_balance', $amount);

            AuditLog::record('wallet.adjusted', $locked, [
                'reference' => $reference,
                'amount' => $amount,
                'reason' => $reason,
                'balance_before' => $before,
                'balance_after' => round($before + $amount, 2),
                'approved_by' => $applier->id,
                'via_pending_adjustment' => $source?->id,
            ], $applier, $locked);

            return $transaction;
        });
    }
}

