<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\WithdrawalRequested;
use App\Services\FlutterwaveService;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class WithdrawalController extends Controller
{
    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');
        $user = auth()->user();

        if (PlatformSetting::get('payouts.paused', false)) {
            return back()->withErrors(['amount' => 'Withdrawals are temporarily paused by the platform.']);
        }

        $availableMethods = array_values(array_intersect(
            ['mtn_momo', 'airtel_money', 'bank_transfer'],
            (array) PlatformSetting::get('wallet.payout_method', ['mtn_momo', 'airtel_money', 'bank_transfer']),
        ));
        $method = (string) $request->input('payout_method', '');
        if ($method === '' && filled($request->input('phone'))) {
            $method = FlutterwaveService::networkForUgandanPhone((string) $request->input('phone')) === 'AIRTEL'
                ? 'airtel_money'
                : 'mtn_momo';
        }
        if (! in_array($method, $availableMethods, true)) {
            return back()->withErrors(['payout_method' => 'This payout method is not currently enabled by the platform.']);
        }

        $min = (int) Policy::get('wallet.min_withdrawal', (int) PlatformSetting::get('min_withdrawal_amount', 10000));
        $currency = PlatformSetting::get('currency', 'UGX');

        $rules = [
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.max(0, (float) $tenant->wallet_balance)],
            'password' => ['required', 'string'],
            'payout_method' => ['required', 'in:'.implode(',', $availableMethods)],
        ];
        if ($method === 'bank_transfer') {
            $rules += [
                'bank_name' => ['required', 'string', 'max:100'],
                'account_name' => ['required', 'string', 'max:120'],
                'account_number' => ['required', 'string', 'max:50'],
            ];
        } else {
            $rules['phone'] = ['required', 'string', 'max:30'];
        }
        $request->merge(['payout_method' => $method]);
        $validated = $request->validate($rules, [
            'amount.max' => "The withdrawal amount cannot exceed your available wallet balance of {$currency} ".number_format($tenant->wallet_balance).'.',
            'amount.min' => "The minimum withdrawal amount is {$currency} ".number_format($min).'.',
        ]);
        if ($method !== 'bank_transfer' && ! FlutterwaveService::normalizeUgandanPhone($validated['phone'])) {
            return back()->withErrors(['phone' => 'Enter a valid Uganda Mobile Money number.']);
        }

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

        $result = DB::transaction(function () use ($tenant, $request, $currency, $limit, $method, $validated): Transaction|string {
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
            $meta = ['payout_method' => $method];
            if ($method === 'bank_transfer') {
                $meta['bank_details_encrypted'] = Crypt::encryptString(json_encode([
                    'bank_name' => $validated['bank_name'],
                    'account_name' => $validated['account_name'],
                    'account_number' => $validated['account_number'],
                ], JSON_THROW_ON_ERROR));
            }

            $withdrawal = Transaction::create([
                'tenant_id' => $lockedTenant->id,
                'type' => 'withdrawal',
                'amount' => $request->amount,
                'platform_fee' => 0,
                'net_amount' => -$request->amount,
                'status' => 'pending',
                'reference' => 'WTH-'.strtoupper(Str::random(6)),
                'description' => $method === 'bank_transfer'
                    ? 'Manual bank transfer payout'
                    : "Withdrawal to {$validated['phone']}",
                'phone' => $method === 'bank_transfer' ? null : $validated['phone'],
                'meta' => $meta,
            ]);

            app(WalletService::class)->apply(
                $lockedTenant,
                -1 * (float) $request->amount,
                WalletService::WITHDRAWAL_DEBIT,
                $withdrawal,
                $method === 'bank_transfer' ? 'Manual bank transfer payout' : "Withdrawal to {$validated['phone']}",
            );

            AuditLog::record('withdrawal.requested', $withdrawal, [
                'reference' => $withdrawal->reference,
                'amount' => (float) $request->amount,
                'payout_method' => $method,
                'destination_phone' => $validated['phone'] ?? null,
            ], auth()->user(), $lockedTenant);

            return $withdrawal;
        });

        if (is_string($result)) {
            return back()->withErrors(['amount' => $result]);
        }

        try {
            $admins = User::withoutTenancy()->role('platform_admin')->get();
            Notification::send($admins, new WithdrawalRequested(
                (int) $tenant->id,
                $tenant->name,
                PlatformSetting::get('currency', 'UGX').' '.number_format((float) $result->amount),
                $result->reference,
                rtrim((string) config('app.url'), '/').route('platform.revenue.index', [], false),
            ));
        } catch (Throwable $exception) {
            Log::error('Unable to notify platform admins of a withdrawal request.', [
                'withdrawal_id' => $result->id,
                'exception' => $exception::class,
            ]);
        }

        return redirect()->route('operator.wallet.index', ['tenant' => $tenant->slug])
            ->with('status', 'Withdrawal request submitted successfully. Funds will be deposited shortly.');
    }
}
