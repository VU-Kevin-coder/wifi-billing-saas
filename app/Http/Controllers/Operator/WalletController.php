<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;

class WalletController extends Controller
{
    public function index(TenantContext $context)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');

        // Dynamic Platform Settings
        $currency = PlatformSetting::get('currency', 'UGX');
        $dateFormat = PlatformSetting::get('date_format', 'd M Y');
        $timezone = PlatformSetting::get('timezone', 'Africa/Kampala');
        $minWithdrawal = (float) PlatformSetting::get('min_withdrawal_amount', 10000);
        $commissionRate = (float) ($tenant->commission_rate ?? PlatformSetting::get('default_commission_rate', 5.00));

        $transactionsQuery = Transaction::where('tenant_id', $tenant->id);

        // 1. Total Online Gross from Transactions
        $onlineGross = (clone $transactionsQuery)->where('type', 'voucher_sale')->where('status', 'completed')->sum('amount');
        if ($onlineGross == 0) {
            $onlineGross = (clone $transactionsQuery)->where('type', 'voucher_sale')->sum('amount');
        }

        // 2. Total Redeemed Vouchers Gross (Cash / Walk-in / Online)
        $voucherGross = (float) Voucher::where('vouchers.tenant_id', $tenant->id)
            ->where('vouchers.status', 'redeemed')
            ->join('packages', 'vouchers.package_id', '=', 'packages.id')
            ->sum('packages.price');

        // Total Gross is the highest of actual voucher revenue or transaction records
        $totalGross = max($onlineGross, $voucherGross);

        // 3. Platform Fees: Either recorded on transactions or calculated from commission rate
        $recordedFees = (float) (clone $transactionsQuery)->where('type', 'voucher_sale')->sum('platform_fee');
        $totalFees = $recordedFees > 0 ? $recordedFees : round($totalGross * ($commissionRate / 100), 2);

        // 4. Payouts
        $totalWithdrawn = (float) (clone $transactionsQuery)->where('type', 'withdrawal')->where('status', 'completed')->sum('amount');
        $pendingPayouts = (float) (clone $transactionsQuery)->where('type', 'withdrawal')->where('status', 'pending')->sum('amount');

        // 5. Transaction History
        $transactions = Transaction::where('tenant_id', $tenant->id)->latest()->paginate(20);

        // Operator contact phone fallback
        $defaultPhone = auth()->user()->phone 
            ?? $tenant->contact_phone 
            ?? ($tenant->settings['support_phone'] ?? '');

        return view('operator.wallet.index', compact(
            'tenant', 
            'transactions', 
            'totalGross', 
            'totalFees', 
            'totalWithdrawn', 
            'pendingPayouts',
            'currency',
            'dateFormat',
            'timezone',
            'minWithdrawal',
            'commissionRate',
            'defaultPhone'
        ));
    }
}


