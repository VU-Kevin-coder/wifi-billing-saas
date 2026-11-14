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

/** Durable work is created only after a caller has verified the provider response. */
class SaleFulfillmentService
{
    private const MAX_ATTEMPTS = 10;

    public function confirm(Tenant $tenant, Package $package, string $phone, Transaction $sale): void
    {
        DB::transaction(function () use ($tenant, $package, $phone, $sale): void {
            $sale = Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($sale->id);
            if ($sale->type !== 'voucher_sale' || (int) $package->tenant_id !== (int) $tenant->id
                || (int) ($sale->meta['package_id'] ?? 0) !== (int) $package->id
                || round((float) $sale->amount, 2) !== round((float) $package->price, 2)
                || $phone === '') {
                throw new \LogicException('The verified sale does not match its tenant and package.');
            }
            if ($sale->status === 'completed') {
                return; // Never create work for old completed sales.
            }
            if (! in_array($sale->status, ['pending', 'failed'], true)) {
                throw new \LogicException('Sale cannot be confirmed in its current state.');
            }
            // Capture the package as it exists at confirmation; never silently use changed terms on retry.
            $currentPackage = Package::withoutTenancy()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($package->id);
            $rate = (float) ($tenant->commission_rate ?? PlatformSetting::get('default_commission_rate', 5.00));
            $fee = round((float) $sale->amount * ($rate / 100), 2);
            $net = round((float) $sale->amount - $fee, 2);
            $sale->update(['status' => 'completed', 'platform_fee' => $fee, 'net_amount' => $net, 'phone' => $phone]);
            app(WalletService::class)->apply($tenant, $net, WalletService::SALE_CREDIT, $sale,
                "Sale: {$package->name}", null, null,
                ['gross' => (float) $sale->amount, 'platform_fee' => $fee, 'package_id' => $package->id]);
            DB::table('sale_fulfillment_work')->insert([
                'tenant_id' => $tenant->id, 'transaction_id' => $sale->id, 'package_id' => $package->id,
                'package_fingerprint' => $this->packageFingerprint($currentPackage),
                'phone' => $phone, 'status' => 'pending', 'attempts' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function packageFingerprint(Package $package): string
    {
        $attributes = $package->getRawOriginal();
        unset($attributes['created_at'], $attributes['updated_at']);
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    /** Only a new durable work row may be retried; a lease handles process death. */
    public function attempt(int $tenantId, int $saleId): ?Voucher
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($tenantId, $saleId, $token): bool {
            $work = DB::table('sale_fulfillment_work')->where('tenant_id', $tenantId)
                ->where('transaction_id', $saleId)->lockForUpdate()->first();
            if (! $work || $work->status === 'completed' || $work->status === 'exhausted'
                || ($work->next_attempt_at && now()->lt($work->next_attempt_at))) {
                return false;
            }
            if ($work->attempts >= self::MAX_ATTEMPTS) {
                DB::table('sale_fulfillment_work')->where('id', $work->id)
                    ->update(['status' => 'exhausted', 'updated_at' => now()]);
                Log::error('Sale fulfillment retries exhausted.', ['tenant_id' => $tenantId, 'transaction_id' => $saleId]);

                return false;
            }
            DB::table('sale_fulfillment_work')->where('id', $work->id)->update([
                'status' => 'running', 'lease_token' => $token, 'attempts' => $work->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);

            return true;
        });
        if (! $claimed) {
            return Voucher::withoutTenancy()->where('tenant_id', $tenantId)->where('sale_transaction_id', $saleId)->first();
        }

        try {
            $result = DB::transaction(function () use ($tenantId, $saleId, $token): array {
                $work = DB::table('sale_fulfillment_work')->where('tenant_id', $tenantId)
                    ->where('transaction_id', $saleId)->lockForUpdate()->first();
                if (! $work || $work->status !== 'running' || $work->lease_token !== $token) {
                    throw new \LogicException('Fulfillment lease lost.');
                }
                $tenant = Tenant::query()->findOrFail($tenantId);
                $sale = Transaction::withoutTenancy()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($saleId);
                $package = Package::withoutTenancy()->where('tenant_id', $tenantId)->findOrFail($work->package_id);
                if ($sale->status !== 'completed' || $sale->type !== 'voucher_sale'
                    || (int) ($sale->meta['package_id'] ?? 0) !== (int) $package->id
                    || round((float) $package->price, 2) !== round((float) $sale->amount, 2)
                    || ! hash_equals($work->package_fingerprint, $this->packageFingerprint($package))) {
                    throw new \LogicException('Paid sale terms changed; manual review required.');
                }

                return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $sale, $package, $work): array {
                    $voucher = Voucher::withoutTenancy()->where('tenant_id', $tenant->id)
                        ->where('sale_transaction_id', $sale->id)->first();
                    if (! $voucher) {
                        $batchCode = 'ONLINE-'.$tenant->id.'-'.$package->id;
                        $batch = VoucherBatch::withoutTenancy()->where('tenant_id', $tenant->id)
                            ->where('batch_code', $batchCode)->first() ?? VoucherBatch::create([
                                'tenant_id' => $tenant->id, 'package_id' => $package->id,
                                'batch_code' => $batchCode, 'quantity' => 0, 'status' => 'active',
                            ]);
                        $days = max(0, (int) PlatformSetting::get('voucher.unused_expiry', 365));
                        $voucher = Voucher::create([
                            'tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id,
                            'package_id' => $package->id, 'sale_transaction_id' => $sale->id,
                            'code' => 'ONL-'.strtoupper(Str::random(6)), 'status' => 'unused',
                            'redeemed_by' => $work->phone, 'expires_at' => $days > 0 ? now()->addDays($days) : null,
                        ]);
                    }
                    $sale->update(['meta' => array_merge($sale->meta ?? [], [
                        'voucher_id' => $voucher->id, 'voucher_code' => $voucher->code,
                    ])]);
                    $session = app(VoucherActivationService::class)->activate(
                        $tenant, $voucher, Request::create('/'), $work->phone, $sale->fresh()->meta ?? []
                    );
                    DB::table('sale_fulfillment_work')->where('id', $work->id)->update([
                        'status' => 'completed', 'lease_token' => null, 'next_attempt_at' => null,
                        'last_error_class' => null, 'updated_at' => now(),
                    ]);

                    return ['voucher' => $voucher, 'session' => $session, 'tenant' => $tenant, 'package' => $package, 'phone' => $work->phone];
                });
            });
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($tenantId, $saleId, $token, $exception): void {
                $work = DB::table('sale_fulfillment_work')->where('tenant_id', $tenantId)
                    ->where('transaction_id', $saleId)->lockForUpdate()->first();
                if ($work && $work->status === 'running' && $work->lease_token === $token) {
                    DB::table('sale_fulfillment_work')->where('id', $work->id)->update([
                        'status' => $work->attempts >= self::MAX_ATTEMPTS ? 'exhausted' : 'pending',
                        'lease_token' => null, 'next_attempt_at' => now()->addMinutes(5),
                        'last_error_class' => $exception::class, 'updated_at' => now(),
                    ]);
                }
            });
            Log::error('Paid sale fulfillment failed.', ['tenant_id' => $tenantId, 'transaction_id' => $saleId, 'exception' => $exception::class]);

            return null;
        }

        // Receipts are best-effort and are never retried by the financial worker.
        try {
            app(PaymentService::class)->sendSaleReceipt($result['tenant'], $result['package'], $result['phone'], $result['voucher'], $result['session']);
        } catch (\Throwable $exception) {
            Log::warning('Sale receipt failed.', ['tenant_id' => $tenantId, 'transaction_id' => $saleId, 'exception' => $exception::class]);
        }

        return $result['voucher'];
    }
}
