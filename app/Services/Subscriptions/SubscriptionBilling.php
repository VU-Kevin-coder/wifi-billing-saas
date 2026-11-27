<?php

namespace App\Services\Subscriptions;

use App\Models\PlatformPlan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SubscriptionBilling
{
    public function __construct(private SubscriptionGateway $gateway) {}

    public function available(): bool
    {
        return config('subscriptions.checkout_enabled') === true && $this->gatewayReady();
    }

    /** Verification remains available independently of the checkout kill switch. */
    public function gatewayReady(): bool
    {
        // Adapter exceptions may contain credentials or provider responses; never report them.
        try {
            return $this->gateway->ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function canVerifyAttempt(object $attempt): bool
    {
        $context = SubscriptionGatewayContext::fromAttempt($attempt);
        if (! $context) {
            return false;
        }

        try {
            return $this->gateway->canVerify($context);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function requireAvailable(): void
    {
        abort_unless($this->available(), 503, 'Subscription checkout is not available.');
    }

    /** Snapshot exactly what is shown for explicit consent; authenticated and short-lived. */
    public function quote(Tenant $tenant, string $key, ?int $planId = null): ?array
    {
        $assignedPlan = $tenant->plan()->first();
        if ($tenant->platform_plan_id !== null) {
            if (! $assignedPlan || ($planId !== null && $planId !== (int) $assignedPlan->id)) {
                return null;
            }
            $plan = $assignedPlan;
        } else {
            $plan = $planId === null ? null : PlatformPlan::query()->find($planId);
        }
        $currency = strtoupper((string) PlatformSetting::get('currency', 'UGX'));
        if (! $plan || $currency !== 'UGX' || ! preg_match('/^([0-9]{1,10})\.00$/', (string) $plan->price, $m) || (int) $m[1] < 1) {
            return null;
        }
        $data = ['tenant_id' => $tenant->id, 'key' => $key, 'plan_id' => $plan->id,
            'plan_name' => $plan->name, 'amount' => (int) $m[1], 'currency' => $currency,
            'expires' => now()->addMinutes(15)->timestamp];

        return $data + ['token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))];
    }

    private function decodeQuote(string $token, Tenant $tenant, string $key): array
    {
        try {
            $quote = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['quote' => 'Invalid quote. Refresh the page and confirm again.']);
        }
        if (! is_array($quote) || ($quote['tenant_id'] ?? null) !== $tenant->id || ($quote['key'] ?? null) !== $key ||
            ! is_int($quote['expires'] ?? null) || ! is_int($quote['plan_id'] ?? null) ||
            ! is_string($quote['plan_name'] ?? null) || ! is_int($quote['amount'] ?? null) ||
            ($quote['currency'] ?? null) !== 'UGX') {
            throw ValidationException::withMessages(['quote' => 'Invalid quote. Refresh the page and confirm again.']);
        }

        return $quote;
    }

    /** New tenants may select an initial plan; assigned tenants can only renew it. */
    public function start(Tenant $tenant, string $key, string $phone, string $token): object
    {
        // An inner commit is only a savepoint: never send a charge before the outer commit.
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Subscription checkout cannot start inside a database transaction.');
        }
        $this->requireAvailable();
        $gatewayContext = $this->gateway->context();
        abort_unless($gatewayContext, 503, 'Subscription payment gateway is not available.');
        $quote = $this->decodeQuote($token, $tenant, $key);
        $hash = hash_hmac('sha256', $phone, (string) config('app.key'));
        [$attempt, $created] = DB::transaction(function () use ($tenant, $key, $hash, $quote, $gatewayContext) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('subscription_attempts')->where('tenant_id', $locked->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                $invoice = DB::table('subscription_invoices')->where('id', $existing->subscription_invoice_id)->where('tenant_id', $locked->id)->first();
                if (! $invoice || ! hash_equals($existing->phone_hash, $hash) || (int) $invoice->plan_id !== $quote['plan_id'] || $invoice->plan_name !== $quote['plan_name'] || (int) $invoice->amount !== $quote['amount'] || $invoice->currency !== $quote['currency']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details.']);
                }

                return [$existing, false];
            }
            if ($quote['expires'] <= now()->timestamp) {
                throw ValidationException::withMessages(['quote' => 'Quote expired. Refresh the page and confirm again.']);
            }
            if ($locked->status === 'suspended' || $locked->subscription_status === 'suspended') {
                throw ValidationException::withMessages(['billing' => 'Suspended accounts require administrator review.']);
            }
            if (DB::table('subscription_invoices')->where('tenant_id', $locked->id)->whereIn('status', ['open', 'review'])->exists()) {
                throw ValidationException::withMessages(['billing' => 'An invoice already requires reconciliation. Contact support before trying again.']);
            }
            if ($locked->platform_plan_id !== null) {
                $plan = $locked->plan()->lockForUpdate()->first();
                if (! $plan || (int) $plan->id !== $quote['plan_id']) {
                    throw ValidationException::withMessages(['quote' => 'Plan changed. Refresh the page and confirm again.']);
                }
            } else {
                $plan = PlatformPlan::query()->whereKey($quote['plan_id'])->lockForUpdate()->first();
            }
            $currency = strtoupper((string) PlatformSetting::get('currency', 'UGX'));
            // Current gateways elsewhere use whole UGX. Do not round, coerce or use floats.
            if (! $plan || $currency !== 'UGX' || ! preg_match('/^([0-9]{1,10})\.00$/', (string) $plan->price, $m) || (int) $m[1] < 1) {
                throw ValidationException::withMessages(['billing' => 'No billable whole-UGX monthly plan is configured.']);
            }
            if ((int) $plan->id !== $quote['plan_id'] || $plan->name !== $quote['plan_name'] || (int) $m[1] !== $quote['amount'] || $currency !== $quote['currency']) {
                throw ValidationException::withMessages(['quote' => 'Plan or price changed. Refresh the page and confirm the new quote.']);
            }
            $time = now();
            $invoiceId = DB::table('subscription_invoices')->insertGetId([
                'tenant_id' => $locked->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
                'amount' => (int) $m[1], 'currency' => $currency, 'status' => 'open', 'created_at' => $time, 'updated_at' => $time,
            ]);
            $attemptId = DB::table('subscription_attempts')->insertGetId([
                'tenant_id' => $locked->id, 'subscription_invoice_id' => $invoiceId, 'idempotency_key' => $key,
                'phone_hash' => $hash, 'reference' => (string) Str::uuid(), 'status' => 'unknown', 'created_at' => $time, 'updated_at' => $time,
            ] + $gatewayContext->databaseAttributes());
            $invoice = DB::table('subscription_invoices')->where('id', $invoiceId)->first();
            $attempt = DB::table('subscription_attempts')->where('id', $attemptId)->first();
            $this->event($invoice, $attempt, 'created', ['status' => 'unknown']);

            return [$attempt, true];
        }, 3);
        if (! $created) {
            return $attempt;
        } // never send twice after an ambiguous response
        $invoice = DB::table('subscription_invoices')->where('id', $attempt->subscription_invoice_id)->first();
        $checkoutUrl = null;
        $returnUrl = route('operator.billing.invoices.show', [
            'tenant' => $tenant->slug,
            'invoice' => $attempt->subscription_invoice_id,
        ]);
        try {
            $checkoutUrl = $this->gateway->start(
                $attempt->reference,
                (int) $invoice->amount,
                $invoice->currency,
                $phone,
                $gatewayContext,
                $returnUrl,
            );
            // A successful submission or hosted redirect does not prove payment.
            // A concurrent reconciliation may already have changed the state; never overwrite it.
        } catch (Throwable $e) {
            // A timeout, or failure to record submission, is ambiguous. Do not log adapter data.
        }

        if (is_string($checkoutUrl)) {
            $encryptedUrl = Crypt::encryptString($checkoutUrl);
            DB::transaction(function () use ($tenant, $attempt, $encryptedUrl): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                DB::table('subscription_attempts')
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                DB::table('subscription_attempts')->where('id', $attempt->id)->update([
                    'checkout_url' => $encryptedUrl,
                    'updated_at' => now(),
                ]);
            }, 3);
        }

        return DB::table('subscription_attempts')->where('id', $attempt->id)->first();
    }

    public function checkoutUrl(object $attempt): ?string
    {
        if (! in_array($attempt->status ?? null, ['unknown', 'pending'], true)
            || ! is_string($attempt->checkout_url ?? null)
            || $attempt->checkout_url === '') {
            return null;
        }
        $invoice = DB::table('subscription_invoices')
            ->where('tenant_id', $attempt->tenant_id)
            ->where('id', $attempt->subscription_invoice_id)
            ->first();
        if (! $invoice || $invoice->status !== 'open') {
            return null;
        }

        $url = Crypt::decryptString($attempt->checkout_url);
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'checkout.obpay.online'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \RuntimeException('Stored hosted checkout URL is not trusted.');
        }

        return $url;
    }

    /** Authenticated provider lookup, then strict comparison inside a locked transaction. */
    public function reconcile(Tenant $tenant, int $attemptId): void
    {
        // The checkout kill switch blocks new collections, not verification of existing ones.
        $attempt = DB::table('subscription_attempts')->where('tenant_id', $tenant->id)->where('id', $attemptId)->first();
        abort_unless($attempt, 404);
        abort_unless($this->canVerifyAttempt($attempt), 503, 'Subscription verification is not available.');
        if (! in_array($attempt->status, ['unknown', 'pending', 'failed'], true)) {
            return;
        }
        // A newer reconciliation must win even when it leaves the same status (e.g. unknown).
        $historyId = DB::table('subscription_history')->where('subscription_attempt_id', $attempt->id)->max('id');
        try {
            $result = $this->gateway->verify(
                $attempt->reference,
                SubscriptionGatewayContext::fromAttempt($attempt),
            );
        } catch (Throwable $e) {
            $result = null;
        }
        DB::transaction(function () use ($tenant, $attempt, $result, $historyId) {
            // Always lock in tenant -> attempt -> invoice order.
            $lockedTenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('subscription_attempts')->where('id', $attempt->id)->where('tenant_id', $lockedTenant->id)->lockForUpdate()->first();
            if (! $current || $current->status !== $attempt->status) {
                return;
            }
            $invoice = DB::table('subscription_invoices')->where('id', $current->subscription_invoice_id)->where('tenant_id', $lockedTenant->id)->lockForUpdate()->first();
            if (! $invoice || $invoice->status !== 'open') {
                return;
            }
            if (DB::table('subscription_history')->where('subscription_attempt_id', $current->id)->max('id') != $historyId) {
                return;
            }
            if ($result === null) {
                if ($current->status !== 'failed') {
                    DB::table('subscription_attempts')->where('id', $current->id)->update(['status' => 'unknown', 'updated_at' => now()]);
                }
                $this->event($invoice, $current, 'verification_unknown', ['status' => $current->status === 'failed' ? 'failed' : 'unknown']);

                return;
            }
            // Exact types and values: adapter must normalize the authenticated response.
            if (($result['reference'] ?? null) !== $current->reference || ($result['amount'] ?? null) !== (int) $invoice->amount || ($result['currency'] ?? null) !== $invoice->currency || ! in_array($result['status'] ?? null, ['pending', 'paid', 'failed'], true)) {
                $this->event($invoice, $current, 'verification_mismatch', ['status' => $current->status === 'failed' ? 'failed' : 'unknown']);
                if ($current->status !== 'failed') {
                    DB::table('subscription_attempts')->where('id', $current->id)->update(['status' => 'unknown', 'updated_at' => now()]);
                }

                return;
            }
            if ($current->status === 'failed' && $result['status'] !== 'paid') {
                // Record even unchanged observations so earlier lookups cannot apply afterward.
                $this->event($invoice, $current, 'verification_after_failed', ['status' => 'failed']);

                return;
            }
            if ($result['status'] === 'pending') {
                if ($current->status !== 'pending') {
                    DB::table('subscription_attempts')->where('id', $current->id)->update(['status' => 'pending', 'updated_at' => now()]);
                }
                $this->event($invoice, $current, 'verified_pending', ['status' => 'pending']);

                return;
            }
            if ($result['status'] === 'failed') {
                DB::table('subscription_attempts')->where('id', $current->id)->update(['status' => 'failed', 'updated_at' => now()]);
                // Keep invoice open to require human reconciliation before another charge.
                $this->event($invoice, $current, 'verified_failed', ['status' => 'failed']);

                return;
            }
            $planWasRemoved = ! PlatformPlan::query()->whereKey($invoice->plan_id)->exists();
            $planWasChanged = $lockedTenant->platform_plan_id !== null && (int) $lockedTenant->platform_plan_id !== (int) $invoice->plan_id;
            $review = $current->status === 'failed' || $lockedTenant->status === 'suspended' || $lockedTenant->subscription_status === 'suspended' || $planWasChanged || $planWasRemoved;
            $now = now();
            $end = null;
            if (! $review) {
                $base = $lockedTenant->subscription_ends_at && $lockedTenant->subscription_ends_at->isFuture() ? $lockedTenant->subscription_ends_at->copy() : $now->copy();
                if ($lockedTenant->subscription_status === 'trial' && $lockedTenant->trial_ends_at && $lockedTenant->trial_ends_at->gt($base)) {
                    $base = $lockedTenant->trial_ends_at->copy();
                }
                $end = $base->addMonthNoOverflow();
                $lockedTenant->update([
                    'platform_plan_id' => $invoice->plan_id,
                    'plan' => $invoice->plan_name,
                    'subscription_status' => 'active',
                    'subscription_ends_at' => $end,
                    'plan_fee' => $invoice->amount,
                    'auto_renew' => false,
                ]);
            }
            DB::table('subscription_attempts')->where('id', $current->id)->update(['status' => 'paid', 'updated_at' => $now]);
            DB::table('subscription_invoices')->where('id', $invoice->id)->update(['status' => $review ? 'review' : 'paid', 'paid_at' => $now, 'entitlement_applied_at' => $review ? null : $now, 'updated_at' => $now]);
            $this->event($invoice, $current, $review ? 'paid_requires_review' : 'paid_entitlement_applied', ['status' => 'paid', 'subscription_ends_at' => $end?->toIso8601String()]);
        }, 3);
    }

    public function applyAdministratorPlanChange(Tenant $tenant, array $attributes, int $actorId): Tenant
    {
        return DB::transaction(function () use ($tenant, $attributes, $actorId) {
            $lockedTenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $oldPlanId = $lockedTenant->platform_plan_id;
            $oldStatus = $lockedTenant->subscription_status;
            $openInvoiceIds = DB::table('subscription_invoices')
                ->where('tenant_id', $lockedTenant->id)
                ->whereIn('status', ['open', 'review'])
                ->orderBy('id')
                ->pluck('id');

            foreach ($openInvoiceIds as $invoiceId) {
                $attempt = DB::table('subscription_attempts')
                    ->where('subscription_invoice_id', $invoiceId)
                    ->where('tenant_id', $lockedTenant->id)
                    ->lockForUpdate()
                    ->first();
                $invoice = DB::table('subscription_invoices')
                    ->where('id', $invoiceId)
                    ->where('tenant_id', $lockedTenant->id)
                    ->lockForUpdate()
                    ->first();
                if (! $invoice) {
                    continue;
                }

                if ($invoice->status === 'open') {
                    DB::table('subscription_invoices')->where('id', $invoice->id)->update([
                        'status' => 'review',
                        'updated_at' => now(),
                    ]);
                }
                if ($attempt && ! in_array($attempt->status, ['paid', 'manual_review'], true)) {
                    DB::table('subscription_attempts')->where('id', $attempt->id)->update([
                        'status' => 'manual_review',
                        'updated_at' => now(),
                    ]);
                }
                $this->event($invoice, $attempt, 'administrator_plan_changed', [
                    'status' => 'manual_review',
                    'actor_id' => $actorId,
                    'previous_plan_id' => $oldPlanId,
                    'previous_subscription_status' => $oldStatus,
                    'new_plan_id' => $attributes['platform_plan_id'],
                    'new_subscription_status' => $attributes['subscription_status'],
                ]);
            }

            $lockedTenant->update($attributes);

            return $lockedTenant->fresh();
        }, 3);
    }

    private function event(object $invoice, ?object $attempt, string $event, array $extra): void
    {
        DB::table('subscription_history')->insert([
            'tenant_id' => $invoice->tenant_id, 'subscription_invoice_id' => $invoice->id,
            'subscription_attempt_id' => $attempt?->id, 'event' => $event,
            'snapshot' => json_encode(array_merge(['plan_id' => $invoice->plan_id, 'plan_name' => $invoice->plan_name, 'amount' => (int) $invoice->amount, 'currency' => $invoice->currency, 'reference' => $attempt?->reference], $extra), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
