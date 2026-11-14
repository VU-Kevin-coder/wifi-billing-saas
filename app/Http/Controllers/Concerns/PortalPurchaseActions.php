<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\PesapalOrderVerifier;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait PortalPurchaseActions
{
    public function purchase(
        Request $request,
        TenantContext $context,
        PaymentService $paymentService,
        ?string $tenant = null
    ) {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        if (Policy::denies($tenantModel, 'portal_sell')) {
            return back()->withErrors(['error' => 'This Wi-Fi operator is not accepting payments right now.']);
        }

        $request->validate([
            'package_id' => [
                'required',
                'integer',
                Rule::exists('packages', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'phone' => ['required', 'string', 'min:9', 'max:15'],
            'email' => ['nullable', 'email', 'max:255'],
            'router_id' => [
                'nullable',
                'integer',
                Rule::exists('routers', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'router' => ['nullable', 'string', 'max:255'],
        ]);

        $package = Package::withoutTenancy()
            ->where('tenant_id', $tenantModel->id)
            ->findOrFail($request->integer('package_id'));
        $phone = $request->input('phone');
        $canonicalPhone = $paymentService->normalizePurchasePhone($phone)
            ?? (string) preg_replace('/\D+/', '', $phone);
        $purchaseIntentHash = hash('sha256', implode('|', [
            $tenantModel->id,
            $package->id,
            $canonicalPhone,
        ]));
        $lock = Cache::lock('portal-purchase-intent:'.$purchaseIntentHash, 15);

        if (! $lock->get()) {
            return back()->withErrors([
                'phone' => 'A payment request for this package and number is already being submitted. Please wait.',
            ]);
        }

        $paymentToken = Str::random(64);

        try {
            $pendingPurchases = Transaction::withoutGlobalScopes()
                ->where('tenant_id', $tenantModel->id)
                ->where('type', 'voucher_sale')
                ->where('status', 'pending')
                ->where(function ($query) use ($purchaseIntentHash, $package): void {
                    $query->where('purchase_intent_hash', $purchaseIntentHash)
                        ->orWhere(function ($legacyQuery) use ($package): void {
                            $legacyQuery->whereNull('purchase_intent_hash')
                                ->where('meta->package_id', $package->id);
                        });
                })
                ->get(['purchase_intent_hash', 'phone', 'meta']);

            $hasMatchingPendingPurchase = $pendingPurchases->contains(function (Transaction $pending) use ($canonicalPhone, $paymentService): bool {
                if ($pending->purchase_intent_hash !== null) {
                    return true;
                }

                $pendingPhone = (string) ($pending->phone ?? $pending->meta['phone'] ?? '');
                $pendingCanonicalPhone = $paymentService->normalizePurchasePhone($pendingPhone)
                    ?? (string) preg_replace('/\D+/', '', $pendingPhone);

                return $pendingCanonicalPhone === $canonicalPhone;
            });

            if ($hasMatchingPendingPurchase) {
                return back()->withErrors([
                    'phone' => 'A payment for this package and number is already pending. Complete it or wait for its status to update.',
                ]);
            }

            $transaction = Transaction::create([
                'tenant_id' => $tenantModel->id,
                'type' => 'voucher_sale',
                'amount' => $package->price,
                'status' => 'pending',
                'reference' => 'ONL-'.strtoupper(Str::random(8)),
                'phone' => $phone,
                'purchase_intent_hash' => $purchaseIntentHash,
                'payment_token_hash' => hash('sha256', $paymentToken),
                'description' => "Online Purchase: {$package->name}",
                'meta' => [
                    'package_id' => $package->id,
                    'phone' => $phone,
                    'router_id' => $request->input('router_id'),
                    'router' => $request->input('router'),
                    'ip' => $request->ip(),
                    'mac' => $request->input('mac') ?? $request->header('X-Client-Mac'),
                    'email' => $request->input('email'),
                ],
            ]);
        } finally {
            $lock->release();
        }

        $result = $paymentService->initiatePackagePayment(
            $tenantModel,
            $package,
            $phone,
            $transaction,
            $request->input('email'),
            $this->portalUrl($request, 'success', $tenantModel)
        );

        if ($result['status'] === 'redirect') {
            session()->put('payment_token', $paymentToken);

            return redirect()->away($result['redirect_url']);
        }

        if (in_array($result['status'], ['simulated_success', 'pending'], true)) {
            session()->put('payment_token', $paymentToken);

            if ($result['authorization_url'] ?? null) {
                return redirect()->away($result['authorization_url']);
            }

            if ($result['status'] === 'simulated_success') {
                return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                    ->with('voucher_code', $result['voucher']->code);
            }

            return redirect()->to($this->portalUrl($request, 'pending', $tenantModel, $transaction));
        }

        return back()->withErrors([
            'phone' => 'Payment failed: '.($result['error'] ?? 'Please try again.'),
        ]);
    }

    public function pesapalCallback(
        Request $request,
        TenantContext $context,
        PesapalOrderVerifier $orderVerifier,
        ?string $tenant = null
    ) {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $orderTrackingId = $request->query('OrderTrackingId');
        $merchantReference = $request->query('OrderMerchantReference');

        if (! $orderTrackingId) {
            return redirect()->to($this->portalUrl($request, 'index', $tenantModel))
                ->withErrors(['error' => 'Payment was cancelled or missing tracking details.']);
        }

        // Verify with Pesapal API
        $consumerKey = PlatformSetting::get('pesapal_consumer_key');
        $consumerSecret = PlatformSetting::getSecret('pesapal_consumer_secret') ?: PlatformSetting::get('pesapal_consumer_secret');
        $environment = PlatformSetting::get('pesapal_environment', 'sandbox');
        $baseUrl = $environment === 'production'
            ? 'https://pay.pesapal.com/v3/api'
            : 'https://cybqa.pesapal.com/pesapalv3/api';

        try {
            $tokenResponse = Http::post("{$baseUrl}/Auth/RequestToken", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]);

            $token = $tokenResponse->json('token');

            $statusResponse = Http::withToken($token)
                ->get("{$baseUrl}/Transactions/GetTransactionStatus?orderTrackingId={$orderTrackingId}");

            $status = $statusResponse->json('payment_status_description') ?? 'FAILED';

            $providerDetails = $statusResponse->json();
            $transaction = $orderVerifier->findTransaction(
                $orderTrackingId,
                $merchantReference,
                (string) $tenantModel->id
            );

            if ($transaction && $orderVerifier->matchesMerchantReference($transaction, $providerDetails)
                && strtoupper($status) === 'COMPLETED' && $transaction->status === 'pending') {
                $package = Package::withoutGlobalScopes()
                    ->where('tenant_id', $tenantModel->id)
                    ->find($transaction->meta['package_id'] ?? null);
                $phone = $transaction->phone ?? ($transaction->meta['phone'] ?? '');

                if ($package && $orderVerifier->matchesCompletedPayment($transaction, $package, $providerDetails)) {
                    $voucher = app(PaymentService::class)->handleSuccessfulPackagePayment(
                        $tenantModel,
                        $package,
                        $phone,
                        $transaction
                    );

                    if (! $voucher) {
                        return redirect()->to($this->portalUrl($request, 'pending', $tenantModel, $transaction));
                    }

                    return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                        ->with('voucher_code', $voucher->code)
                        ->with('auto_activate', true);
                }
            }

            if ($transaction && $orderVerifier->matchesMerchantReference($transaction, $providerDetails)
                && strtoupper($status) === 'COMPLETED' && $transaction->status === 'completed') {
                $package = Package::withoutGlobalScopes()
                    ->where('tenant_id', $tenantModel->id)
                    ->find($transaction->meta['package_id'] ?? null);
                $voucherCode = $transaction->meta['voucher_code'] ?? null;

                if ($package && $orderVerifier->matchesCompletedPayment($transaction, $package, $providerDetails)) {
                    return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                        ->with('voucher_code', $voucherCode)
                        ->with('auto_activate', true);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Pesapal callback error: '.$e->getMessage());
        }

        return redirect()->to($this->portalUrl($request, 'index', $tenantModel))
            ->withErrors(['error' => 'Payment was not successful or is still processing.']);
    }
}
