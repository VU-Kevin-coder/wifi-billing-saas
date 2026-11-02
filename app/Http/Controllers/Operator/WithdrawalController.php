<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WithdrawalController extends Controller
{
    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');
        $user = auth()->user();

        $minWithdrawal = (float) \App\Models\PlatformSetting::get('min_withdrawal_amount', 10000);
        $currency = \App\Models\PlatformSetting::get('currency', 'UGX');

        $request->validate([
            'amount' => ['required', 'numeric', 'min:' . $minWithdrawal, 'max:' . max(0, (float) $tenant->wallet_balance)],
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'amount.max' => "The withdrawal amount cannot exceed your available wallet balance of {$currency} " . number_format($tenant->wallet_balance) . '.',
            'amount.min' => "The minimum withdrawal amount is {$currency} " . number_format($minWithdrawal) . '.',
        ]);

        // 1. Verify Password
        if (!Hash::check($request->password, $user->password)) {
            return back()->withErrors(['password' => 'The provided password does not match our records.']);
        }

        DB::transaction(function () use ($tenant, $request) {
            // Deduct from wallet immediately (to prevent double spending)
            $tenant->decrement('wallet_balance', $request->amount);

            // Create the withdrawal transaction
            Transaction::create([
                'tenant_id' => $tenant->id,
                'type' => 'withdrawal',
                'amount' => $request->amount,
                'platform_fee' => 0,
                'net_amount' => -$request->amount, // Negative because it leaves the wallet
                'status' => 'pending', // Pending until admin approves or MoMo API processes
                'reference' => 'WTH-' . strtoupper(Str::random(6)),
                'description' => "Withdrawal to {$request->phone}",
                'phone' => $request->phone,
            ]);
        });

        return redirect()->route('operator.wallet.index', ['tenant' => $tenant->slug])
            ->with('status', 'Withdrawal request submitted successfully. Funds will be deposited shortly.');
    }
}

