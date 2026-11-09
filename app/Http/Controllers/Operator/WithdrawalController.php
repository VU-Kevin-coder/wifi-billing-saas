<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use App\Services\WalletService;
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

        $min = (int) Policy::get('wallet.min_withdrawal', (int) PlatformSetting::get('min_withdrawal_amount', 10000));
        $currency = PlatformSetting::get('currency', 'UGX');

        $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.max(0, (float) $tenant->wallet_balance)],
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'amount.max' => "The withdrawal amount cannot exceed your available wallet balance of {$currency} ".number_format($tenant->wallet_balance).'.',
            'amount.min' => "The minimum withdrawal amount is {$currency} ".number_format($min).'.',
        ]);

        $limit = (int) Policy::get('wallet.withdrawal_pending_limit', 1);
        $existingCount = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->count();

        if ($existingCount >= $limit) {
            return back()->withErrors(['amount' => "You already have {$existingCount} pending withdrawal(s). Max allowed: {$limit}."]);
        }

        // Verify password before taking a wallet lock.
        if (! Hash::check($request->password, $user->password)) {
            return back()->withErrors(['password' => 'The provided password does not match our records.']);
        }

        $error = DB::transaction(function () use ($tenant, $request, $currency, $limit) {
            $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $existingPending = Transaction::where('tenant_id', $lockedTenant->id)
                ->where('type', 'withdrawal')
                ->where('status', 'pending')
                ->count();

            if ($existingPending >= $limit) {
                return "You already have {$existingPending} pending withdrawal(s). Max allowed: {$limit}.";
            }

            if ((float) $request->amount > (float) $lockedTenant->wallet_balance) {
                return "The withdrawal amount cannot exceed your available wallet balance of {$currency} ".number_format($lockedTenant->wallet_balance).'.';
            }

            // Create the withdrawal transaction
            $withdrawal = Transaction::create([
                'tenant_id' => $lockedTenant->id,
                'type' => 'withdrawal',
                'amount' => $request->amount,
                'platform_fee' => 0,
                'net_amount' => -$request->amount,
                'status' => 'pending',
                'reference' => 'WTH-'.strtoupper(Str::random(6)),
                'description' => "Withdrawal to {$request->phone}",
                'phone' => $request->phone,
            ]);

            app(WalletService::class)->apply(
                $lockedTenant,
                -1 * (float) $request->amount,
                WalletService::WITHDRAWAL_DEBIT,
                $withdrawal,
                "Withdrawal to {$request->phone}",
            );

            AuditLog::record('withdrawal.requested', $withdrawal, [
                'reference' => $withdrawal->reference,
                'amount' => (float) $request->amount,
                'destination_phone' => $request->phone,
            ], auth()->user(), $lockedTenant);

            return null;
        });

        if ($error) {
            return back()->withErrors(['amount' => $error]);
        }

        return redirect()->route('operator.wallet.index', ['tenant' => $tenant->slug])
            ->with('status', 'Withdrawal request submitted successfully. Funds will be deposited shortly.');
    }
}
