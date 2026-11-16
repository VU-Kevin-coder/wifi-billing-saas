<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\Concerns\InitiatesPackagePayments;
use App\Services\Tenancy\TenantContext;
use App\Support\SmsTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    use InitiatesPackagePayments;

    public function __construct(
        private readonly MtnMomoService $mtn,
        private readonly AirtelMoneyService $airtel,
        private readonly VoucherActivationService $voucherActivation,
        private readonly TenantContext $tenantContext,
    ) {}

    public function normalizePurchasePhone(string $phone): ?string
    {
        return $this->normalizeUgandanPhone($phone);
    }

    public function reconcilePendingPayment(Transaction $transaction, ?string $provider = null): string
    {
        $transaction->refresh();
        if ($transaction->status !== 'pending') {
            return $transaction->status;
        }

        $provider ??= $transaction->meta['payment_provider'] ?? null;
        $reference = $transaction->provider_reference;
        if (! $reference || ! in_array($provider, ['MTN', 'AIRTEL'], true)) {
            return 'pending';
        }

        try {
            $details = $provider === 'MTN'
                ? $this->mtn->verify($reference)
                : $this->airtel->verify($reference);
        } catch (\Throwable $exception) {
            Log::warning('Unable to verify a mobile money collection.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return 'unavailable';
        }

        $providerStatus = $provider === 'MTN'
            ? strtoupper((string) ($details['status'] ?? ''))
            : strtoupper((string) ($details['data']['transaction']['status'] ?? ''));
        $successStatus = $provider === 'MTN' ? 'SUCCESSFUL' : 'TS';
        $failureStatuses = $provider === 'MTN'
            ? ['FAILED', 'REJECTED']
            : ['TF', 'FAILED', 'REJECTED'];

        if ($providerStatus === $successStatus) {
            if (! $this->verifiedDetailsMatch($transaction, $provider, $details)) {
                Log::error('Verified mobile money payment did not match its local transaction.', [
                    'transaction_id' => $transaction->id,
                    'provider' => $provider,
                ]);

                return 'mismatch';
            }

            $tenant = Tenant::query()->find($transaction->tenant_id);
            $packageId = $transaction->meta['package_id'] ?? null;
            $package = $packageId
                ? Package::withoutTenancy()
                    ->where('tenant_id', $transaction->tenant_id)
                    ->find($packageId)
                : null;
            $phone = $transaction->phone ?? $transaction->meta['phone'] ?? null;

            if (! $tenant || ! $package || ! is_string($phone) || $phone === '') {
                Log::error('Verified mobile money payment is missing fulfillment details.', [
                    'transaction_id' => $transaction->id,
                ]);

                return 'fulfillment_data_missing';
            }

            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return $voucher ? 'completed' : 'fulfillment_pending';
        }

        if (in_array($providerStatus, $failureStatuses, true)) {
            DB::transaction(function () use ($transaction): void {
                $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ($locked->status === 'pending') {
                    $locked->update(['status' => 'failed']);
                }
            });

            return 'failed';
        }

        return 'pending';
    }

    private function updatePending(Transaction $transaction, array $attributes): void
    {
        DB::transaction(function () use ($transaction, $attributes): void {
            $locked = Transaction::withoutTenancy()->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status !== 'pending') {
                return;
            }
            // Preserve any concurrent metadata written while a provider request was in flight.
            if (isset($attributes['meta'])) {
                $attributes['meta'] = array_merge($locked->meta ?? [], $attributes['meta']);
            }
            $locked->update($attributes);
        });
        $transaction->refresh();
    }

    private function markFailedIfPending(Transaction $transaction): void
    {
        Transaction::withoutTenancy()->where('tenant_id', $transaction->tenant_id)
            ->whereKey($transaction->id)->where('status', 'pending')->update(['status' => 'failed']);
    }

    private function verifiedDetailsMatch(Transaction $transaction, string $provider, array $details): bool
    {
        if ($provider === 'MTN') {
            $expectedCurrency = PlatformSetting::get('mtn_environment', 'sandbox') === 'sandbox'
                ? 'EUR'
                : 'UGX';

            return (string) ($details['externalId'] ?? '') === $transaction->reference
                && is_numeric($details['amount'] ?? null)
                && (float) $details['amount'] === (float) $transaction->amount
                && strtoupper((string) ($details['currency'] ?? '')) === $expectedCurrency;
        }

        $providerTransaction = $details['data']['transaction'] ?? null;
        if (! is_array($providerTransaction)) {
            return false;
        }

        $reportedId = $providerTransaction['id'] ?? null;
        $reportedAmount = $providerTransaction['amount'] ?? null;
        $reportedCurrency = $providerTransaction['currency'] ?? null;

        // Airtel's v1 status response identifies the queried payment by ID and
        // reports its status; amount and currency may not be returned. Always
        // bind the result to the stored provider ID and local purchase terms,
        // while validating amount/currency whenever Airtel includes them.
        $providerReference = (string) ($transaction->provider_reference ?? '');
        if ($providerReference === ''
            || ! is_string($reportedId)
            || $reportedId === ''
            || ! hash_equals($providerReference, $reportedId)) {
            return false;
        }

        $packageId = $transaction->meta['package_id'] ?? null;
        $package = $packageId
            ? Package::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->find($packageId)
            : null;
        if (! $package
            || strtoupper((string) $package->currency) !== 'UGX'
            || (float) $package->price <= 0
            || (float) $package->price !== (float) round((float) $package->price)
            || (float) $package->price !== (float) $transaction->amount) {
            return false;
        }

        return ($reportedAmount === null || (is_numeric($reportedAmount)
                && (float) $reportedAmount === (float) $package->price))
            && ($reportedCurrency === null || strtoupper((string) $reportedCurrency) === 'UGX');
    }

    private function normalizeUgandanPhone(string $phone): ?string
    {
        $number = preg_replace('/\D+/', '', $phone);

        // 1. Allow MTN Sandbox test numbers to bypass validation
        $sandboxNumbers = ['46733123450', '46733123451', '46733123452', '46733123453', '46733123454'];
        if (in_array($number, $sandboxNumbers)) {
            return $number;
        }

        // 2. Normalize real Ugandan numbers
        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            $number = '256'.substr($number, 1);
        } elseif (strlen($number) === 9) {
            $number = '256'.$number;
        }

        return preg_match('/^256\d{9}$/', $number) ? $number : null;
    }

    private function networkForPhone(string $msisdn): ?string
    {
        // 1. Force MTN for Sandbox test numbers
        if (str_starts_with($msisdn, '467')) {
            return 'MTN';
        }

        // 2. Detect network for real Ugandan numbers
        $prefix = substr($msisdn, 3, 2);

        return match ($prefix) {
            '70', '74', '75' => 'AIRTEL',
            '76', '77', '78', '79' => 'MTN',
            default => null,
        };
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
