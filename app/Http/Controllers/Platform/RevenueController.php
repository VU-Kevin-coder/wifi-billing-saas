<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
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
        $totalCommissions = (float) Transaction::where('type', 'voucher_sale')->sum('platform_fee');
        
        // Subscription Revenue
        $subscriptionRevenue = (float) Tenant::where('subscription_status', 'active')->sum('plan_fee');

        // Platform Payouts (Total completed withdrawals to operators)
        $platformPayouts = (float) Transaction::where('type', 'withdrawal')->where('status', 'completed')->sum('amount'); 

        // 2. Fetch Operators with their Wallet Data & Pending Withdrawals
        $operators = Tenant::withCount([
            'transactions as pending_withdrawals_count' => function ($query) {
                $query->where('type', 'withdrawal')->where('status', 'pending');
            }
        ])->withSum([
            'transactions as pending_withdrawals_amount' => function ($query) {
                $query->where('type', 'withdrawal')->where('status', 'pending');
            }
        ], 'amount')
        ->orderBy('wallet_balance', 'desc')
        ->get();

        return view('platform.revenue.index', compact(
            'totalCommissions', 'subscriptionRevenue', 'platformPayouts', 'operators', 'currency'
        ));
    }

    public function approveWithdrawal(Transaction $transaction)
    {
        if ($transaction->status !== 'pending') {
            return back()->withErrors(['error' => 'This transaction is no longer pending.']);
        }

        DB::transaction(function () use ($transaction) {
            // Mark as completed
            $transaction->update(['status' => 'completed']);
            
            // In a live integration, the MTN B2C disbursement is triggered here
        });

        return back()->with('status', 'Withdrawal approved and funds released.');
    }

    public function rejectWithdrawal(Transaction $transaction)
    {
        if ($transaction->status !== 'pending') {
            return back()->withErrors(['error' => 'This transaction is no longer pending.']);
        }

        DB::transaction(function () use ($transaction) {
            // 1. Mark as rejected
            $transaction->update(['status' => 'rejected']);
            
            // 2. Refund the money back to the operator's wallet
            $tenant = $transaction->tenant;
            $tenant->increment('wallet_balance', $transaction->amount);
        });

        return back()->with('status', 'Withdrawal rejected. Funds returned to operator wallet.');
    }

    public function adjustBalance(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'reason' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($tenant, $validated) {
            // Create a transaction record
            Transaction::create([
                'tenant_id' => $tenant->id,
                'type' => 'adjustment',
                'amount' => abs($validated['amount']),
                'platform_fee' => 0,
                'net_amount' => $validated['amount'], // Can be positive or negative
                'status' => 'completed',
                'description' => $validated['reason'],
                'reference' => 'ADJ-' . strtoupper(Str::random(6)),
            ]);

            // Update wallet balance
            $tenant->increment('wallet_balance', $validated['amount']);
        });

        return back()->with('status', 'Operator balance adjusted successfully.');
    }
}

