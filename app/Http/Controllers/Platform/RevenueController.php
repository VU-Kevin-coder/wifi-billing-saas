<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            }
        ])->withSum([
            'transactions as pending_withdrawals_amount' => function ($query) {
                $query->withoutTenancy()->where('type', 'withdrawal')->where('status', 'pending');
            }
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
            Tenant::query()->whereKey($locked->tenant_id)->increment('wallet_balance', $locked->amount);

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

    public function adjustBalance(Request $request, Tenant $tenant)
    {
        $max = (float) \App\Services\Policy\Policy::get('wallet.max_adjustment', (float) config('payments.max_wallet_adjustment', 1000000));

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'between:-'.$max.','.$max],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        if ($amount === 0.0) {
            return back()->withErrors(['amount' => 'The adjustment amount cannot be zero.']);
        }

        $error = DB::transaction(function () use ($request, $tenant, $validated, $amount) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $before = (float) $locked->wallet_balance;

            if ($before + $amount < 0) {
                return 'This adjustment would make the wallet balance negative.';
            }

            $reference = 'ADJ-'.strtoupper(Str::random(10));

            Transaction::create([
                'tenant_id' => $locked->id,
                'type' => 'adjustment',
                'amount' => abs($amount),
                'platform_fee' => 0,
                'net_amount' => $amount, // positive or negative
                'status' => 'completed',
                'description' => $validated['reason'],
                'reference' => $reference,
            ]);

            $locked->increment('wallet_balance', $amount);

            AuditLog::record('wallet.adjusted', $locked, [
                'reference' => $reference,
                'amount' => $amount,
                'reason' => $validated['reason'],
                'balance_before' => $before,
                'balance_after' => round($before + $amount, 2),
            ], $request->user(), $locked);

            return null;
        });

        if ($error) {
            return back()->withErrors(['amount' => $error]);
        }

        return back()->with('status', 'Operator balance adjusted successfully.');
    }
}
