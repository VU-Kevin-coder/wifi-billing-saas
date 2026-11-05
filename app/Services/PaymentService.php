<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly MtnMomoService $mtn,
        private readonly AirtelMoneyService $airtel,
        private readonly VoucherActivationService $voucherActivation,
        private readonly TenantContext $tenantContext,
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

        if ((string) $package->tenant_id !== (string) $tenant->id
            || (string) $transaction->tenant_id !== (string) $tenant->id
            || $transaction->type !== 'voucher_sale') {
            return ['status' => 'failed', 'error' => 'Payment details are invalid.'];
        }

        if (! empty($transaction->provider_reference)) {
            return ['status' => 'pending'];
        }

        $msisdn = $this->normalizeUgandanPhone($phone);
        $network = $msisdn ? $this->networkForPhone($msisdn) : null;

        if (! $msisdn || ! $network) {
            $transaction->update(['status' => 'failed']);

            return ['status' => 'failed', 'error' => 'Enter a valid MTN or Airtel Uganda number.'];
        }

        // 1. Ensure price is a valid whole number (no decimals)
        if ((float) $package->price <= 0
            || (float) $package->price !== (float) round((float) $package->price)
            || (float) $package->price !== (float) $transaction->amount) {
            $transaction->update(['status' => 'failed']);

            return ['status' => 'failed', 'error' => 'Mobile money payments require a valid whole-number package price.'];
        }

        // Simulation must be explicitly enabled AND only run in non-production
        // environments. Both gates are required so a stray config value can
        // never issue vouchers without a real payment in production.
        if (config('payments.simulation_enabled', false)
            && app()->environment(['local', 'testing'])) {
            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return ['status' => 'simulated_success', 'voucher' => $voucher];
        }

        $configured = $network === 'MTN' ? $this->mtn->isConfigured() : $this->airtel->isConfigured();
        if (! $configured) {
            $transaction->update(['status' => 'failed']);

            return ['status' => 'failed', 'error' => "{$network} payments are not configured."];
        }

        $meta = $transaction->meta ?? [];

        $reference = (string) Str::uuid();
        $transaction->update([
            'provider_reference' => $reference,
            'meta' => array_merge($meta, [
                'payment_provider' => $network,
                'provider_submission' => 'submitting',
            ]),
        ]);

        try {
            if ($network === 'MTN') {
                $response = $this->mtn->initiate($transaction, $package, $msisdn, $reference);
                $accepted = $response->successful();
                $rejected = $response->clientError() && $response->status() !== 409;
                $providerReference = $reference;
            } else {
                $result = $this->airtel->initiate($msisdn, (float) $package->price, $reference);
                $response = $result['response'];
                $accepted = $response->successful()
                    && $response->json('status.success') === true;
                $rejected = ($response->clientError() && $response->status() !== 409)
                    || $response->json('status.success') === false;
                $providerReference = (string) $result['provider_reference'];
            }

            $transaction->update([
                'provider_reference' => $providerReference,
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_submission' => $accepted ? 'submitted' : ($rejected ? 'failed' : 'unknown'),
                ]),
            ]);

            if (! $accepted) {
                if ($rejected) {
                    $transaction->update(['status' => 'failed']);
                }

                Log::warning($rejected
                    ? 'Mobile money provider rejected a collection request.'
                    : 'Mobile money collection submission has an unknown outcome.', [
                        'transaction_id' => $transaction->id,
                        'provider' => $network,
                        'http_status' => $response->status(),
                    ]);

                return $rejected
                    ? ['status' => 'failed', 'error' => "{$network} payment could not be initiated."]
                    : ['status' => 'pending', 'message' => 'Payment status is being confirmed. Check your phone.'];
            }

            return ['status' => 'pending'];
        } catch (\Throwable $exception) {
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_submission' => 'unknown',
                ]),
            ]);
            Log::error('Mobile money collection submission could not be confirmed.', [
                'transaction_id' => $transaction->id,
                'provider' => $network,
                'exception' => $exception::class,
            ]);

            return ['status' => 'pending', 'message' => 'Payment status is being confirmed. Check your phone.'];
        }
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

            $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return 'completed';
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

        return ($reportedId === null || (string) $reportedId === $transaction->provider_reference)
            && ($reportedAmount === null || (is_numeric($reportedAmount)
                && (float) $reportedAmount === (float) $transaction->amount))
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

    /**
     * Credit the payment and immediately redeem its voucher.
     */
    public function handleSuccessfulPackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): Voucher
    {
        return app(TenantContext::class)->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $package, $phone, $transaction) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status !== 'pending') {
                $voucherId = $transaction->meta['voucher_id'] ?? null;

                if ($voucherId) {
                    return Voucher::withoutTenancy()->findOrFail($voucherId);
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

            $tenant->increment('wallet_balance', $net);

            $batchCode = 'ONLINE-'.$tenant->id.'-'.$package->id;
            $batch = VoucherBatch::withoutTenancy()
                ->where('batch_code', $batchCode)
                ->first()
                ?? VoucherBatch::create([
                    'tenant_id' => $tenant->id,
                    'package_id' => $package->id,
                    'batch_code' => $batchCode,
                    'quantity' => 0,
                    'status' => 'active',
                ]);

            $voucher = Voucher::create([
                'tenant_id' => $tenant->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'ONL-'.strtoupper(Str::random(6)),
                'status' => 'unused',
                'redeemed_by' => $phone,
            ]);

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $voucher->code,
                ]),
            ]);

            $this->tenantContext->runAs($tenant, fn () => $this->voucherActivation->activate(
                $tenant,
                $voucher,
                Request::create('/'),
                $phone,
                $transaction->fresh()->meta ?? [],
            ));

            return $voucher;
        }));
    }
}
