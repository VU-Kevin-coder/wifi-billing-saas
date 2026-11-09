<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use App\Services\Policy\AdjustmentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueController extends Controller
{
    public function index()
    {
        $currency = PlatformSetting::get('currency', 'UGX');

        // 1. Platform KPIs
        // Total Commissions Earned
        $totalCommissions = (float) Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->where('status', 'completed')
            ->sum('platform_fee');

        // Subscription Revenue
        $subscriptionRevenue = (float) Tenant::where('subscription_status', 'active')->sum('plan_fee');

        // Platform Payouts (Total completed withdrawals to operators)
        $platformPayouts = (float) Transaction::withoutTenancy()->where('type', 'withdrawal')->where('status', 'completed')->sum('amount');

        // 2. Fetch Operators with their Wallet Data & Pending Withdrawals
        $operators = Tenant::withCount([
            'transactions as pending_withdrawals_count' => function ($query) {
                $query->withoutTenancy()->where('type', 'withdrawal')->where('status', 'pending');
            },
        ])->withSum([
            'transactions as pending_withdrawals_amount' => function ($query) {
                $query->withoutTenancy()->where('type', 'withdrawal')->where('status', 'pending');
            },
        ], 'amount')
            ->orderBy('wallet_balance', 'desc')
            ->get();

        return view('platform.revenue.index', compact(
            'totalCommissions', 'subscriptionRevenue', 'platformPayouts', 'operators', 'currency'
        ));
    }

    /**
     * Platform admins have no tenant context, so implicit model binding (which
     * applies TenantScope and fails closed) cannot be used for these routes.
     */
    private function findWithdrawal(int|string $id): Transaction
    {
        return Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->findOrFail($id);
    }

    public function approveWithdrawal(int|string $transaction, FlutterwaveWithdrawalService $withdrawals)
    {
        $transaction = $this->findWithdrawal($transaction);
        $result = $withdrawals->approve($transaction);
        AuditLog::record(
            $result['ok'] ? 'withdrawal.approved' : 'withdrawal.approval_failed',
            $transaction,
            [
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'result' => $result['message'],
            ],
        );
        if (! $result['ok']) {
            return back()->withErrors(['error' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    public function rejectWithdrawal(int|string $transaction)
    {
        $transaction = $this->findWithdrawal($transaction);
        if ($transaction->status !== 'pending') {
            return back()->withErrors(['error' => 'This transaction is no longer pending.']);
        }

        $rejected = DB::transaction(function () use ($transaction) {
            $locked = Transaction::withoutTenancy()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status !== 'pending') {
                return 'The withdrawal is no longer pending.';
            }

            if (! empty($locked->meta['flutterwave_transfer_id'])
                || in_array($locked->meta['flutterwave_transfer_submission'] ?? null, ['submitting', 'submitted', 'unknown'], true)) {
                return 'A Flutterwave transfer has been submitted or may be in progress. Reconcile it before rejecting the withdrawal.';
            }

            $locked->update(['status' => 'rejected']);
            app(WalletService::class)->apply(
                $locked->tenant_id,
                $locked->amount,
                WalletService::WITHDRAWAL_REFUND,
                $locked,
                'Withdrawal rejected; funds returned',
            );

            return null;
        });

        if ($rejected) {
            return back()->withErrors(['error' => $rejected]);
        }

        AuditLog::record('withdrawal.rejected', $transaction, [
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
        ]);

        return back()->with('status', 'Withdrawal rejected. Funds returned to operator wallet.');
    }

    public function adjustBalance(Request $request, Tenant $tenant, AdjustmentService $service)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'reason' => 'required|string|min:10|max:255',
        ]);

        if ((float) $validated['amount'] === 0.0) {
            return back()->withErrors(['amount' => 'The adjustment amount cannot be zero.']);
        }

        try {
            $result = $service->request(
                $tenant,
                (float) $validated['amount'],
                $validated['reason'],
                $request->user()
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        if ($result['status'] === 'blocked') {
            return back()->withErrors(['amount' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }
}
