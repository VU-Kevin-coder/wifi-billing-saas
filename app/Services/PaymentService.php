<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\Payments\PaymentGatewayService;
use App\Support\SmsTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayService $gateways,
    ) {}

    public function initiatePackagePayment(
        Tenant $tenant,
        Package $package,
        string $phone,
        Transaction $transaction,
        ?string $email = null,
        ?string $returnUrl = null,
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

            return $voucher
                ? ['status' => 'simulated_success', 'voucher' => $voucher]
                : ['status' => 'pending'];
        }

        return $this->gateways->initiateCollection(
            $tenant,
            $package,
            $phone,
            (string) ($transaction->meta['customer_name'] ?? 'Wi-Fi Customer'),
            $email,
            $transaction,
            $returnUrl,
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
        if (! is_string($provider) || ! in_array($provider, ['obpay', 'pawapay', 'iotec'], true)) {
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

            if (! $tenant || ! $package || ! is_string($phone) || $phone === '') {
                Log::error('Verified payment is missing fulfillment details.', [
                    'transaction_id' => $transaction->id,
                ]);

                return 'fulfillment_data_missing';
            }

            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return $voucher ? 'completed' : 'fulfillment_pending';
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

    /** Caller must have verified the provider response before invoking this method. */
    public function handleSuccessfulPackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): ?Voucher
    {
        $fulfillment = app(SaleFulfillmentService::class);
        $fulfillment->confirm($tenant, $package, $phone, $transaction);

        return $fulfillment->attempt((int) $tenant->id, (int) $transaction->id);
    }

    public function sendSaleReceipt(Tenant $tenant, Package $package, string $phone, Voucher $voucher, Session $session): void
    {
        if ($session->status === 'active' && PlatformSetting::get('sms.voucher_receipt', true)) {
            $this->sendVoucherReceipt($tenant, $package, $phone, $voucher, $session);
        }
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
