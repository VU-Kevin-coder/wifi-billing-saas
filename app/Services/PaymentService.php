<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Tenancy\TenantContext;
use App\Support\SmsTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayService $gateways,
        private readonly VoucherActivationService $voucherActivation,
        private readonly TenantContext $tenantContext,
    ) {}

    public function initiatePackagePayment(
        Tenant $tenant,
        Package $package,
        string $phone,
        string $customerName,
        Transaction $transaction,
        ?string $email = null,
    ): array {
        if ($transaction->status !== 'pending') {
            return ['status' => $transaction->status, 'error' => 'This payment is no longer pending.'];
        }

        if ((int) $package->tenant_id !== (int) $tenant->id
            || (int) $transaction->tenant_id !== (int) $tenant->id
            || $transaction->type !== 'voucher_sale') {
            return ['status' => 'failed', 'error' => 'Payment details are invalid.'];
        }

        if (! empty($transaction->provider_reference)) {
            return ['status' => 'pending'];
        }

        if (config('payments.simulation_enabled', false)
            && app()->environment(['local', 'testing'])) {
            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return ['status' => 'simulated_success', 'voucher' => $voucher];
        }

        return $this->gateways->initiateCollection(
            $tenant,
            $package,
            $phone,
            $customerName,
            $email,
            $transaction,
        );
    }

    public function normalizePurchasePhone(string $phone): ?string
    {
        return $this->gateways->normalizeUgandanPhone($phone);
    }

    public function reconcilePendingPayment(Transaction $transaction, ?string $provider = null): string
    {
        $transaction->refresh();
        if ($transaction->status !== 'pending') {
            return $transaction->status;
        }

        $provider ??= $transaction->meta['payment_provider'] ?? null;
        if (! is_string($provider) || ! in_array($provider, ['obpay', 'pawapay', 'xente'], true)) {
            return 'pending';
        }

        $verification = $this->gateways->verifyCollection($transaction);
        $status = $verification['status'] ?? 'pending';

        if ($status === 'completed') {
            $tenant = Tenant::query()->find($transaction->tenant_id);
            $packageId = $transaction->meta['package_id'] ?? null;
            $package = $packageId
                ? Package::withoutTenancy()
                    ->where('tenant_id', $transaction->tenant_id)
                    ->find($packageId)
                : null;
            $phone = $transaction->phone ?? $transaction->meta['phone'] ?? null;
            $customerName = $transaction->meta['customer_name'] ?? $transaction->meta['name'] ?? 'Wi-Fi customer';

            if (! $tenant || ! $package || ! is_string($phone) || $phone === '') {
                Log::error('Verified payment is missing fulfillment details.', [
                    'transaction_id' => $transaction->id,
                ]);

                return 'fulfillment_data_missing';
            }

            $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return 'completed';
        }

        if ($status === 'failed') {
            DB::transaction(function () use ($transaction): void {
                $locked = Transaction::withoutTenancy()
                    ->where('tenant_id', $transaction->tenant_id)
                    ->lockForUpdate()
                    ->findOrFail($transaction->id);
                if ($locked->status === 'pending') {
                    $locked->update(['status' => 'failed']);
                }
            });

            return 'failed';
        }

        return $status;
    }

    public function handleSuccessfulPackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): Voucher
    {
        $result = app(TenantContext::class)->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $package, $phone, $transaction): array {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ((int) $transaction->tenant_id !== (int) $tenant->id
                || (int) $package->tenant_id !== (int) $tenant->id
                || round((float) $transaction->amount, 2) !== round((float) $package->price, 2)) {
                throw new \LogicException('The verified sale does not match its tenant and package.');
            }

            if ($transaction->status !== 'pending') {
                $voucherId = $transaction->meta['voucher_id'] ?? null;
                $saleVoucher = Voucher::withoutTenancy()
                    ->where('tenant_id', $tenant->id)
                    ->where('sale_transaction_id', $transaction->id)
                    ->first();

                if ($saleVoucher && $voucherId && (int) $voucherId !== (int) $saleVoucher->id) {
                    throw new \LogicException("Transaction {$transaction->id} has inconsistent voucher linkage.");
                }

                if ($saleVoucher || $voucherId) {
                    return [
                        'voucher' => $saleVoucher ?? Voucher::withoutTenancy()
                            ->where('tenant_id', $tenant->id)
                            ->findOrFail($voucherId),
                        'session' => null,
                        'send_receipt' => false,
                    ];
                }

                throw new \LogicException("Transaction {$transaction->id} has already been processed.");
            }

            $commissionRate = (float) ($tenant->commission_rate ?? PlatformSetting::get('default_commission_rate', 5.00));
            $fee = round($transaction->amount * ($commissionRate / 100), 2);
            $net = round($transaction->amount - $fee, 2);

            $transaction->update([
                'status' => 'completed',
                'platform_fee' => $fee,
                'net_amount' => $net,
                'phone' => $phone,
            ]);

            app(WalletService::class)->apply(
                $tenant,
                $net,
                WalletService::SALE_CREDIT,
                $transaction,
                "Sale: {$package->name}",
                null,
                null,
                ['gross' => (float) $transaction->amount, 'platform_fee' => $fee, 'package_id' => $package->id],
            );

            $batchCode = 'ONLINE-'.$tenant->id.'-'.$package->id;
            $batch = VoucherBatch::withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->where('batch_code', $batchCode)
                ->first()
                ?? VoucherBatch::create([
                    'tenant_id' => $tenant->id,
                    'package_id' => $package->id,
                    'batch_code' => $batchCode,
                    'quantity' => 0,
                    'status' => 'active',
                ]);

            $voucherExpiryDays = max(0, (int) PlatformSetting::get('voucher.unused_expiry', 365));
            $voucher = Voucher::create([
                'tenant_id' => $tenant->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'sale_transaction_id' => $transaction->id,
                'code' => 'ONL-'.strtoupper(Str::random(6)),
                'status' => 'unused',
                'redeemed_by' => $phone,
                'expires_at' => $voucherExpiryDays > 0
                    ? now()->addDays($voucherExpiryDays)
                    : null,
            ]);

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $voucher->code,
                ]),
            ]);

            $session = $this->tenantContext->runAs($tenant, fn () => $this->voucherActivation->activate(
                $tenant,
                $voucher,
                Request::create('/'),
                $phone,
                $transaction->fresh()->meta ?? [],
            ));

            return ['voucher' => $voucher, 'session' => $session, 'send_receipt' => true];
        }));

        if ($result['send_receipt'] && PlatformSetting::get('sms.voucher_receipt', true)) {
            $this->sendVoucherReceipt($tenant, $package, $phone, $result['voucher'], $result['session']);
        }

        return $result['voucher'];
    }

    private function sendVoucherReceipt(Tenant $tenant, Package $package, string $phone, Voucher $voucher, Session $session): void
    {
        $cap = (int) config('sms.monthly_cap_per_tenant', 300);
        $sentThisMonth = SmsMessage::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $capped = $cap > 0 && $sentThisMonth >= $cap;
        $template = (string) PlatformSetting::get(
            'sms.template',
            'Your {package} pass is active until {expires}. Code: {code}. Help: {support_phone}',
        );
        $message = SmsTemplate::render($template, [
            'package' => $package->name,
            'expires' => $session->expires_at?->format('d M Y H:i') ?? 'no expiry',
            'code' => $voucher->code,
            'support_phone' => (string) PlatformSetting::get('support_phone', ''),
        ]);
        $result = $capped
            ? ['success' => false, 'message' => 'Monthly SMS cap reached.']
            : SmsService::send($phone, $message);

        SmsMessage::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'session_id' => $session->id,
            'type' => 'voucher_receipt',
            'recipient' => SmsService::normalizePhoneNumber($phone) ?? $phone,
            'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
            'segments' => SmsTemplate::segments($message),
            'detail' => Str::limit((string) ($result['message'] ?? ''), 250, ''),
        ]);
    }
}
