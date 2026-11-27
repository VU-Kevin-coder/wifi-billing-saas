<?php

namespace Tests\Feature;

use App\Models\PlatformPlan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionGateway;
use App\Services\Subscriptions\SubscriptionGatewayContext;
use App\Services\Subscriptions\UnavailableGateway;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FakeSubscriptionGateway implements SubscriptionGateway
{
    public int $starts = 0;

    public array $charges = [];

    public bool $throwOnStart = false;

    public ?string $redirectUrl = null;

    public bool $isReady = true;

    public array $verifications = [];

    public ?array $result = null;

    public bool $throwOnVerify = false;

    public bool $throwOnReady = false;

    public int $readyCalls = 0;

    public $onStart = null;

    public $onVerify = null;

    public function ready(): bool
    {
        $this->readyCalls++;
        if ($this->throwOnReady) {
            throw new \RuntimeException('SECRET_READY');
        }

        return $this->isReady;
    }

    public function context(): ?SubscriptionGatewayContext
    {
        return new SubscriptionGatewayContext('testing', str_repeat('a', 64), 'sandbox');
    }

    public function canVerify(SubscriptionGatewayContext $context): bool
    {
        if ($this->throwOnReady) {
            throw new \RuntimeException('SECRET_READY');
        }

        return $this->isReady;
    }

    public function start(
        string $reference,
        int $amount,
        string $currency,
        string $phone,
        SubscriptionGatewayContext $context,
        ?string $returnUrl = null,
    ): ?string {
        $this->starts++;
        $this->charges[] = compact('reference', 'amount', 'currency', 'phone');
        if ($this->onStart) {
            ($this->onStart)($reference);
        }
        if ($this->throwOnStart) {
            throw new \RuntimeException('SECRET_START +256700123456 provider-token');
        }

        return $this->redirectUrl;
    }

    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array
    {
        $this->verifications[] = $reference;
        if ($this->onVerify) {
            ($this->onVerify)($reference);
        }
        if ($this->throwOnVerify) {
            throw new \RuntimeException('SECRET_VERIFY +256700123456 provider-token');
        }

        return $this->result;
    }
}

class SubscriptionBillingTest extends TestCase
{
    // Real commits are essential: RefreshDatabase wraps each test in a transaction.
    use DatabaseMigrations;

    // The repository has an unrelated SQLite-incompatible migration rollback.
    // migrate:fresh on each test still provides isolation without enclosing transactions.
    public function runDatabaseMigrations()
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    private Tenant $tenant;

    private PlatformPlan $plan;

    private User $finance;

    private FakeSubscriptionGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['operator_admin', 'operator_finance', 'operator_support', 'platform_admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->plan = PlatformPlan::create(['name' => 'Test plan', 'price' => 120000, 'trial_days' => 7, 'max_customers' => 100, 'max_routers' => 3]);
        $this->tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active', 'platform_plan_id' => $this->plan->id, 'subscription_status' => 'active', 'subscription_ends_at' => '2030-01-31 12:00:00']);
        $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->finance->assignRole('operator_finance');
        URL::defaults(['tenant' => $this->tenant->slug]);
        $this->gateway = new FakeSubscriptionGateway;
        $this->app->instance(SubscriptionGateway::class, $this->gateway);
        config(['subscriptions.checkout_enabled' => true]);
    }

    private function service(): SubscriptionBilling
    {
        return $this->app->make(SubscriptionBilling::class);
    }

    private function makeAttempt(?string $key = null): object
    {
        $key ??= (string) Str::uuid();
        $quote = $this->service()->quote($this->tenant, $key);
        if (! $quote) {
            throw ValidationException::withMessages(['quote' => 'No quote']);
        }

        return $this->service()->start($this->tenant, $key, '+256700123456', $quote['token']);
    }

    private function form(string $key, ?Tenant $tenant = null): array
    {
        $quote = $this->service()->quote($tenant ?? $this->tenant, $key);

        return ['idempotency_key' => $key, 'phone' => '+256700123456', 'quote' => $quote['token'], 'confirm_quote' => '1'];
    }

    private function verified(object $attempt, string $status = 'paid'): array
    {
        return ['reference' => $attempt->reference, 'amount' => 120000, 'currency' => 'UGX', 'status' => $status];
    }

    public function test_start_rejects_outer_transactions_before_availability_or_writes_and_submits_after_commit(): void
    {
        $form = $this->form((string) Str::uuid());
        DB::beginTransaction();
        try {
            try {
                $this->service()->start($this->tenant, $form['idempotency_key'], $form['phone'], $form['quote']);
                $this->fail('An outer transaction must be rejected.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('inside a database transaction', $e->getMessage());
            }
            $this->assertSame(0, $this->gateway->readyCalls);
            $this->assertSame(0, $this->gateway->starts);
            $this->assertDatabaseCount('subscription_invoices', 0);
            $this->assertDatabaseCount('subscription_attempts', 0);
            $this->assertDatabaseCount('subscription_history', 0);
        } finally {
            DB::rollBack();
        }
        $atStart = null;
        $this->gateway->onStart = function () use (&$atStart) {
            $atStart = [DB::transactionLevel(), DB::table('subscription_attempts')->count(), DB::table('subscription_attempts')->value('status')];
        };
        $a = $this->service()->start($this->tenant, $form['idempotency_key'], $form['phone'], $form['quote']);
        $this->assertSame([0, 1, 'unknown'], $atStart);
        $this->assertSame('unknown', $a->status);
        $this->assertSame(1, $this->gateway->starts);
    }

    public function test_gateway_exceptions_are_not_reported_and_leave_ambiguous_state(): void
    {
        $this->mock(ExceptionHandler::class)->shouldNotReceive('report');
        $this->gateway->throwOnStart = true;
        $a = $this->makeAttempt();
        $this->assertSame('unknown', $a->status);
        $this->gateway->throwOnVerify = true;
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->assertDatabaseHas('subscription_history', ['subscription_attempt_id' => $a->id, 'event' => 'verification_unknown']);
        $this->gateway->throwOnReady = true;
        $this->assertFalse($this->service()->available());
        try {
            $this->service()->reconcile($this->tenant, $a->id);
            $this->fail('Unavailable');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $history = DB::table('subscription_history')->pluck('snapshot')->implode(' ');
        $this->assertStringNotContainsString('SECRET_', $history);
        $this->assertStringNotContainsString('provider-token', $history);
        $this->assertStringNotContainsString('+256700123456', $history);
    }

    public function test_submission_does_not_overwrite_concurrent_ambiguous_verification(): void
    {
        $this->gateway->onStart = function ($reference) {
            $a = DB::table('subscription_attempts')->where('reference', $reference)->first();
            $this->gateway->result = array_replace($this->verified($a), ['amount' => 1]);
            $this->service()->reconcile($this->tenant, $a->id);
        };
        $a = $this->makeAttempt();
        $this->assertSame('unknown', $a->status);
        $this->assertDatabaseHas('subscription_history', ['subscription_attempt_id' => $a->id, 'event' => 'verification_mismatch']);
        $this->assertDatabaseMissing('subscription_history', ['subscription_attempt_id' => $a->id, 'event' => 'submitted']);
    }

    public function test_submission_preserves_concurrent_paid_and_closed_invoice_states(): void
    {
        $this->gateway->onStart = function ($reference) {
            $a = DB::table('subscription_attempts')->where('reference', $reference)->first();
            $this->gateway->result = $this->verified($a);
            $this->service()->reconcile($this->tenant, $a->id);
        };
        $a = $this->makeAttempt();
        $this->assertSame('paid', $a->status);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'paid']);
        $this->assertDatabaseMissing('subscription_history', ['subscription_attempt_id' => $a->id, 'event' => 'submitted']);

        $this->gateway->onStart = function ($reference) {
            $a = DB::table('subscription_attempts')->where('reference', $reference)->first();
            DB::table('subscription_invoices')->where('id', $a->subscription_invoice_id)->update(['status' => 'review']);
        };
        $b = $this->makeAttempt();
        $this->assertSame('unknown', $b->status);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $b->subscription_invoice_id, 'status' => 'review']);
        $this->assertDatabaseMissing('subscription_history', ['subscription_attempt_id' => $b->id, 'event' => 'submitted']);
    }

    public function test_ambiguous_verification_demotes_pending_but_preserves_failed(): void
    {
        $a = $this->makeAttempt();
        $this->assertSame('unknown', $a->status);
        $this->gateway->result = $this->verified($a, 'pending');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'pending']);
        $this->gateway->result = null;
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->gateway->result = $this->verified($a, 'failed');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->gateway->throwOnVerify = true;
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'failed']);
    }

    public function test_stale_verification_does_not_override_newer_ambiguity_or_manual_status(): void
    {
        $this->gateway->throwOnStart = true;
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a, 'pending');
        $this->gateway->onVerify = function () use ($a) {
            $this->gateway->onVerify = null;
            $this->gateway->result = array_replace($this->verified($a), ['amount' => 1]);
            $this->service()->reconcile($this->tenant, $a->id);
            $this->gateway->result = $this->verified($a, 'pending');
        };
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->assertDatabaseMissing('subscription_history', ['subscription_attempt_id' => $a->id, 'event' => 'verified_pending']);
        DB::table('subscription_attempts')->where('id', $a->id)->update(['status' => 'manual_review']);
        $before = count($this->gateway->verifications);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertSame($before, count($this->gateway->verifications));
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'manual_review']);
    }

    public function test_older_paid_lookup_cannot_override_a_newer_pending_observation(): void
    {
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a, 'pending');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->gateway->onVerify = function () use ($a) {
            $this->gateway->onVerify = null;
            $this->gateway->result = $this->verified($a, 'pending');
            $this->service()->reconcile($this->tenant, $a->id);
            $this->gateway->result = $this->verified($a, 'paid');
        };
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'pending']);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'open', 'entitlement_applied_at' => null]);
    }

    public function test_default_off_and_unavailable_gateway_never_charges(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), ['idempotency_key' => (string) Str::uuid(), 'phone' => '+256700123456'])->assertStatus(503);
        $this->assertSame(0, $this->gateway->starts);
        $this->assertDatabaseCount('subscription_invoices', 0);
        config(['subscriptions.checkout_enabled' => true]);
        $this->app->instance(SubscriptionGateway::class, new UnavailableGateway);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), ['idempotency_key' => (string) Str::uuid(), 'phone' => '+256700123456'])->assertStatus(503);
    }

    public function test_checkout_disabled_still_reconciles_an_unknown_attempt_without_new_collections(): void
    {
        $this->gateway->throwOnStart = true;
        $a = $this->makeAttempt();
        $this->assertSame('unknown', $a->status);
        $this->gateway->result = $this->verified($a);
        config(['subscriptions.checkout_enabled' => false]);

        $form = $this->form((string) Str::uuid());
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), $form)->assertStatus(503);
        try {
            $this->service()->start($this->tenant, $form['idempotency_key'], $form['phone'], $form['quote']);
            $this->fail('The service must also reject checkout when disabled.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->gateway->result = array_replace($this->verified($a), ['amount' => 1]);
        $this->post(route('operator.billing.check', ['attempt' => $a->id]))->assertRedirect();
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        $this->gateway->result = $this->verified($a);
        $this->post(route('operator.billing.check', ['attempt' => $a->id]))->assertRedirect();
        $this->post(route('operator.billing.check', ['attempt' => $a->id]))->assertRedirect();

        $this->assertSame(1, $this->gateway->starts);
        $this->assertSame([$a->reference, $a->reference], $this->gateway->verifications);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'paid']);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'paid']);
        $this->assertSame('2030-02-28', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        $this->assertSame(1, DB::table('subscription_history')->where('event', 'paid_entitlement_applied')->count());
    }

    public function test_unready_gateway_blocks_verification_and_checkout_regardless_of_switch(): void
    {
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a);
        $this->gateway->isReady = false;
        $historyCount = DB::table('subscription_history')->count();
        $this->actingAs($this->finance);

        foreach ([false, true] as $enabled) {
            config(['subscriptions.checkout_enabled' => $enabled]);
            $this->post(route('operator.billing.check', ['attempt' => $a->id]))->assertStatus(503);
            $this->post(route('operator.billing.renew'), $this->form((string) Str::uuid()))->assertStatus(503);
        }

        $this->assertSame(1, $this->gateway->starts);
        $this->assertSame([], $this->gateway->verifications);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'open', 'entitlement_applied_at' => null]);
        $this->assertSame($historyCount, DB::table('subscription_history')->count());
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));

        $this->app->instance(SubscriptionGateway::class, new UnavailableGateway);
        $this->post(route('operator.billing.check', ['attempt' => $a->id]))->assertStatus(503);
    }

    public function test_checkout_disabled_status_check_remains_tenant_scoped(): void
    {
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a);
        config(['subscriptions.checkout_enabled' => false]);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $other->id]);
        $user->assignRole('operator_finance');
        URL::defaults(['tenant' => $other->slug]);

        $this->actingAs($user)->post(route('operator.billing.check', ['attempt' => $a->id]))->assertNotFound();
        $this->assertSame([], $this->gateway->verifications);
        $this->assertSame(1, $this->gateway->starts);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'open']);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
    }

    public function test_server_derived_invoice_idempotency_and_history(): void
    {
        $key = (string) Str::uuid();
        $form = $this->form($key);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), $form + ['amount' => 1, 'currency' => 'USD', 'plan_id' => 999])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), $form)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $this->gateway->starts);
        $this->assertDatabaseHas('subscription_invoices', ['tenant_id' => $this->tenant->id, 'amount' => 120000, 'currency' => 'UGX', 'plan_name' => 'Test plan']);
        $this->assertDatabaseCount('subscription_attempts', 1);
        $attempt = DB::table('subscription_attempts')->where('idempotency_key', $key)->first();
        $this->assertSame('testing', $attempt->gateway_provider);
        $this->assertSame(str_repeat('a', 64), $attempt->gateway_account_fingerprint);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertDatabaseCount('transactions', 0);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), array_replace($form, ['phone' => '+256700999999']))->assertSessionHasErrors();
        $this->actingAs($this->finance)->get(route('operator.billing.index'))->assertOk()->assertSee('Test plan')->assertSee('120,000')->assertSee('Recorded events')->assertSee('Unknown');
        $this->actingAs($this->finance)->get(route('operator.billing.invoices.show', ['invoice' => $attempt->subscription_invoice_id]))
            ->assertOk()->assertSee('Invoice #'.$attempt->subscription_invoice_id)
            ->assertSee('Print / Save PDF')->assertSee('Monthly renewal of Test plan')->assertSee($attempt->reference);
        $this->plan->update(['name' => 'Edited plan', 'price' => 500]);
        $this->assertDatabaseHas('subscription_invoices', ['plan_name' => 'Test plan', 'amount' => 120000]);
    }

    public function test_hosted_checkout_url_is_encrypted_and_only_available_for_open_attempts(): void
    {
        $this->gateway->redirectUrl = 'https://checkout.obpay.online/pay/order-123';
        $key = (string) Str::uuid();
        $this->actingAs($this->finance)
            ->post(route('operator.billing.renew'), $this->form($key))
            ->assertRedirect($this->gateway->redirectUrl);
        $attempt = DB::table('subscription_attempts')->where('idempotency_key', $key)->first();
        $stored = DB::table('subscription_attempts')->where('id', $attempt->id)->value('checkout_url');

        $this->assertNotSame($this->gateway->redirectUrl, $stored);
        $this->assertSame($this->gateway->redirectUrl, $this->service()->checkoutUrl($attempt));

        DB::table('subscription_attempts')->where('id', $attempt->id)->update(['status' => 'paid']);
        $attempt = DB::table('subscription_attempts')->where('id', $attempt->id)->first();
        $this->assertNull($this->service()->checkoutUrl($attempt));
    }

    public function test_unknown_timeout_is_not_retried_and_never_assumed_failed(): void
    {
        $this->gateway->throwOnStart = true;
        $key = (string) Str::uuid();
        $a = $this->makeAttempt($key);
        $this->assertSame('unknown', $a->status);
        $this->makeAttempt($key);
        $this->assertSame(1, $this->gateway->starts);
        $this->gateway->result = null;
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'unknown']);
        $this->expectException(ValidationException::class);
        $this->makeAttempt();
    }

    public function test_only_matching_verified_payment_applies_entitlement_once(): void
    {
        $a = $this->makeAttempt();
        foreach (['reference' => 'wrong', 'amount' => 1, 'currency' => 'USD', 'status' => 'garbage'] as $field => $value) {
            $this->gateway->result = array_replace($this->verified($a), [$field => $value]);
            $this->service()->reconcile($this->tenant, $a->id);
            $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        }
        $this->gateway->result = $this->verified($a, 'pending');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'pending']);
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertSame('2030-02-28', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'paid']);
        $this->assertSame(1, DB::table('subscription_history')->where('event', 'paid_entitlement_applied')->count());
    }

    public function test_suspended_or_changed_plan_payments_require_review(): void
    {
        $a = $this->makeAttempt();
        $this->tenant->update(['subscription_status' => 'suspended']);
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'review', 'entitlement_applied_at' => null]);
        $this->assertSame('suspended', $this->tenant->fresh()->subscription_status);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
    }

    public function test_changed_plan_and_verified_failure_never_grant_entitlement(): void
    {
        $a = $this->makeAttempt();
        $otherPlan = PlatformPlan::create(['name' => 'Other plan', 'price' => 200000, 'trial_days' => 0, 'max_customers' => 10, 'max_routers' => 1]);
        $this->tenant->update(['platform_plan_id' => $otherPlan->id]);
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'review']);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
    }

    public function test_verified_failure_stays_open_for_manual_reconciliation(): void
    {
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a, 'failed');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'failed']);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'open']);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        $this->expectException(ValidationException::class);
        $this->makeAttempt();
    }

    public function test_fractional_price_and_suspended_account_fail_closed(): void
    {
        $this->plan->update(['price' => '12.50']);
        try {
            $this->makeAttempt();
            $this->fail('Fractional UGX must not be rounded.');
        } catch (ValidationException $e) {
            $this->assertSame(0, $this->gateway->starts);
        }
        $this->plan->update(['price' => '120000.00']);
        $this->tenant->update(['status' => 'suspended']);
        $this->expectException(ValidationException::class);
        $this->makeAttempt();
    }

    public function test_authorization_and_tenant_scoping(): void
    {
        $a = $this->makeAttempt();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $other->id]);
        $user->assignRole('operator_finance');
        URL::defaults(['tenant' => $other->slug]);
        $this->actingAs($user)->post(route('operator.billing.check', ['attempt' => $a->id]))->assertNotFound();
        $this->actingAs($user)->get(route('operator.billing.index'))->assertOk()->assertDontSee($a->reference);
        $support = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $support->assignRole('operator_support');
        URL::defaults(['tenant' => $this->tenant->slug]);
        $this->actingAs($support)->post(route('operator.billing.renew'), ['idempotency_key' => (string) Str::uuid(), 'phone' => '+256700123456'])->assertForbidden();
    }

    public function test_quote_consent_and_stale_or_tampered_requests_never_charge(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->finance);
        $key = (string) Str::uuid();
        $form = $this->form($key);
        $page = $this->get(route('operator.billing.index'))->assertOk()->assertSee('Confirm one month of Test plan for UGX 120,000')->assertSee('confirm_quote');
        $this->post(route('operator.billing.renew'), array_diff_key($form, ['confirm_quote' => 1]))->assertSessionHasErrors('confirm_quote');
        $tampered = $form['quote'];
        $tampered[intdiv(strlen($tampered), 2)] = $tampered[intdiv(strlen($tampered), 2)] === 'A' ? 'B' : 'A';
        $this->post(route('operator.billing.renew'), array_replace($form, ['quote' => $tampered]))->assertSessionHasErrors('quote');
        $this->post(route('operator.billing.renew'), array_replace($form, ['idempotency_key' => (string) Str::uuid()]))->assertSessionHasErrors('quote');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'another', 'status' => 'active', 'platform_plan_id' => $this->plan->id]);
        $otherForm = $this->form($key, $other);
        $this->post(route('operator.billing.renew'), $otherForm)->assertSessionHasErrors('quote');
        $this->plan->update(['price' => '130000.00']);
        $this->post(route('operator.billing.renew'), $form)->assertSessionHasErrors('quote');
        $this->plan->update(['price' => '120000.00', 'name' => 'Changed']);
        $this->post(route('operator.billing.renew'), $form)->assertSessionHasErrors('quote');
        $this->plan->update(['name' => 'Test plan']);
        PlatformSetting::set('currency', 'USD');
        $this->post(route('operator.billing.renew'), $form)->assertSessionHasErrors();
        PlatformSetting::set('currency', 'UGX');
        $this->travel(15)->minutes();
        $this->post(route('operator.billing.renew'), $form)->assertSessionHasErrors('quote');
        $this->assertSame(0, $this->gateway->starts);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_unassigned_tenant_can_select_a_plan_and_receives_it_only_after_verified_payment(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->tenant->update([
            'platform_plan_id' => null,
            'plan' => 'Starter',
            'plan_fee' => 0,
            'subscription_status' => 'trial',
            'subscription_ends_at' => null,
        ]);
        $this->actingAs($this->finance)->get(route('operator.billing.index'))
            ->assertOk()
            ->assertSee('Choose a plan and complete checkout')
            ->assertSee('Continue to secure checkout')
            ->assertSee('Test plan')
            ->assertDontSee('Self-serve plan changes coming soon');

        $key = (string) Str::uuid();
        $quote = $this->service()->quote($this->tenant->fresh(), $key, (int) $this->plan->id);
        $this->assertNotNull($quote);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), [
            'idempotency_key' => $key,
            'quote' => $quote['token'],
            'phone' => '+256700123456',
            'confirm_quote' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $attempt = DB::table('subscription_attempts')->where('idempotency_key', $key)->first();
        $this->assertNotNull($attempt);
        $this->assertDatabaseHas('subscription_invoices', [
            'id' => $attempt->subscription_invoice_id,
            'plan_id' => $this->plan->id,
            'plan_name' => $this->plan->name,
            'amount' => 120000,
            'status' => 'open',
        ]);
        $this->assertNull($this->tenant->fresh()->platform_plan_id);

        $this->gateway->result = $this->verified($attempt);
        $this->service()->reconcile($this->tenant, $attempt->id);
        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'platform_plan_id' => $this->plan->id,
            'plan' => $this->plan->name,
            'subscription_status' => 'active',
            'plan_fee' => 120000,
        ]);
        $this->assertDatabaseHas('subscription_invoices', [
            'id' => $attempt->subscription_invoice_id,
            'status' => 'paid',
        ]);
        $this->assertNotNull(DB::table('subscription_invoices')->where('id', $attempt->subscription_invoice_id)->value('entitlement_applied_at'));
    }

    public function test_quote_binds_plan_and_amount_to_actual_charge_and_expired_retry_does_not_recharge(): void
    {
        $form = $this->form((string) Str::uuid());
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), $form)->assertSessionHasNoErrors();
        $this->assertSame(120000, $this->gateway->charges[0]['amount']);
        $this->assertSame('UGX', $this->gateway->charges[0]['currency']);
        $this->assertDatabaseHas('subscription_invoices', ['plan_id' => $this->plan->id, 'plan_name' => 'Test plan', 'amount' => 120000, 'currency' => 'UGX']);
        $this->travel(16)->minutes();
        $this->post(route('operator.billing.renew'), $form)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->gateway->starts);
    }

    public function test_plan_switch_before_confirmation_is_rejected(): void
    {
        $form = $this->form((string) Str::uuid());
        $other = PlatformPlan::create(['name' => 'Other', 'price' => 120000, 'trial_days' => 0, 'max_customers' => 10, 'max_routers' => 1]);
        $this->tenant->update(['platform_plan_id' => $other->id]);
        $this->actingAs($this->finance)->post(route('operator.billing.renew'), $form)->assertSessionHasErrors('quote');
        $this->assertSame(0, $this->gateway->starts);
    }

    public function test_early_trial_payment_preserves_latest_remaining_time(): void
    {
        $this->travelTo(Carbon::parse('2029-12-01 12:00:00'));
        $this->tenant->update(['subscription_status' => 'trial', 'trial_ends_at' => '2030-01-31 12:00:00', 'subscription_ends_at' => '2029-12-31 12:00:00']);
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertSame('2030-02-28 12:00:00', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_failure_then_late_paid_requires_review_without_entitlement(): void
    {
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a, 'failed');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->actingAs($this->finance)->get(route('operator.billing.index'))->assertSee('Check status');
        $this->gateway->result = array_replace($this->verified($a), ['amount' => 1]);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'failed']);
        $this->gateway->result = $this->verified($a, 'pending');
        $this->service()->reconcile($this->tenant, $a->id);
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertDatabaseHas('subscription_invoices', ['id' => $a->subscription_invoice_id, 'status' => 'review', 'entitlement_applied_at' => null]);
        $this->assertDatabaseHas('subscription_attempts', ['id' => $a->id, 'status' => 'paid']);
        $this->assertSame('2030-01-31', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d'));
        $this->assertSame(1, DB::table('subscription_history')->where('event', 'paid_requires_review')->count());
    }

    public function test_expired_subscription_renews_from_payment_time_not_old_end_or_stale_trial(): void
    {
        $this->travelTo(Carbon::parse('2031-01-31 12:30:00'));
        $this->tenant->update(['subscription_status' => 'active', 'subscription_ends_at' => '2030-01-31 12:00:00', 'trial_ends_at' => '2032-01-01 12:00:00']);
        $a = $this->makeAttempt();
        $this->gateway->result = $this->verified($a);
        $this->service()->reconcile($this->tenant, $a->id);
        $this->assertSame('2031-02-28 12:30:00', $this->tenant->fresh()->subscription_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_rendered_confirmation_submits_the_same_quote_as_displayed(): void
    {
        $page = $this->actingAs($this->finance)->get(route('operator.billing.index'))->assertOk()
            ->assertSee('Confirm one month of Test plan for UGX 120,000');
        $html = $page->getContent();
        $this->assertSame(1, preg_match('/name="idempotency_key" value="([^"]+)"/', $html, $key));
        $this->assertSame(1, preg_match('/name="quote" value="([^"]+)"/', $html, $quote));
        $this->post(route('operator.billing.renew'), ['idempotency_key' => $key[1], 'quote' => html_entity_decode($quote[1]), 'phone' => '+256700123456', 'confirm_quote' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(120000, $this->gateway->charges[0]['amount']);
        $this->assertSame('UGX', $this->gateway->charges[0]['currency']);
    }
}
