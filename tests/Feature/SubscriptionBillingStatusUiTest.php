<?php

namespace Tests\Feature;

use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionGateway;
use App\Services\Subscriptions\SubscriptionGatewayContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StatusUiGateway implements SubscriptionGateway
{
    public bool $isReady = true;

    public bool $throwOnReady = false;

    public int $starts = 0;

    public int $verifies = 0;

    public function ready(): bool
    {
        if ($this->throwOnReady) {
            throw new \RuntimeException('secret provider response');
        }

        return $this->isReady;
    }

    public function context(): ?SubscriptionGatewayContext
    {
        return new SubscriptionGatewayContext('testing', str_repeat('b', 64), 'sandbox');
    }

    public function canVerify(SubscriptionGatewayContext $context): bool
    {
        if ($this->throwOnReady) {
            throw new \RuntimeException('secret provider response');
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

        return null;
    }

    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array
    {
        $this->verifies++;

        return null;
    }
}

class SubscriptionBillingStatusUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $finance;

    private StatusUiGateway $gateway;

    private int $attemptId;

    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['operator_admin', 'operator_finance', 'operator_support', 'platform_admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $plan = PlatformPlan::create(['name' => 'Status plan', 'price' => 120000, 'trial_days' => 0, 'max_customers' => 100, 'max_routers' => 3]);
        $this->tenant = Tenant::create(['name' => 'Workspace A', 'slug' => 'workspace-a', 'status' => 'active', 'platform_plan_id' => $plan->id, 'subscription_status' => 'active']);
        $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->finance->assignRole('operator_finance');
        URL::defaults(['tenant' => $this->tenant->slug]);
        $this->invoiceId = DB::table('subscription_invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'amount' => 120000, 'currency' => 'UGX', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->attemptId = DB::table('subscription_attempts')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'subscription_invoice_id' => $this->invoiceId,
            'idempotency_key' => (string) Str::uuid(),
            'phone_hash' => str_repeat('a', 64),
            'reference' => (string) Str::uuid(),
            'gateway_provider' => 'testing',
            'gateway_account_fingerprint' => str_repeat('b', 64),
            'gateway_environment' => 'sandbox',
            'status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->gateway = new StatusUiGateway;
        $this->app->instance(SubscriptionGateway::class, $this->gateway);
        config(['subscriptions.checkout_enabled' => false]);
    }

    public function test_disabled_checkout_shows_only_existing_actionable_status_checks_when_gateway_ready(): void
    {
        $this->actingAs($this->finance);
        foreach (['unknown', 'pending', 'failed'] as $status) {
            DB::table('subscription_attempts')->where('id', $this->attemptId)->update(['status' => $status]);
            $this->get(route('operator.billing.index'))->assertOk()
                ->assertSee('Check status')->assertSee(route('operator.billing.check', ['attempt' => $this->attemptId]), false)
                ->assertDontSee(route('operator.billing.renew'), false)->assertDontSee('Request renewal');
        }
        $this->post(route('operator.billing.check', ['attempt' => $this->attemptId]))->assertRedirect();
        $this->assertSame(1, $this->gateway->verifies);
        $this->assertSame(0, $this->gateway->starts);
        DB::table('subscription_attempts')->where('id', $this->attemptId)->update(['status' => 'paid']);
        $this->get(route('operator.billing.index'))->assertDontSee('Check status');
        DB::table('subscription_attempts')->where('id', $this->attemptId)->update(['status' => 'pending']);
        DB::table('subscription_invoices')->where('id', $this->invoiceId)->update(['status' => 'review']);
        $this->get(route('operator.billing.index'))->assertDontSee('Check status');
    }

    public function test_unready_gateway_does_not_show_status_check_regardless_of_checkout_switch(): void
    {
        $this->actingAs($this->finance);
        $this->gateway->isReady = false;
        foreach ([false, true] as $enabled) {
            config(['subscriptions.checkout_enabled' => $enabled]);
            $this->get(route('operator.billing.index'))->assertOk()->assertDontSee('Check status')->assertDontSee('Request renewal');
            $this->post(route('operator.billing.check', ['attempt' => $this->attemptId]))->assertStatus(503);
        }
        $this->assertSame(0, $this->gateway->starts);
        $this->assertSame(0, $this->gateway->verifies);
    }

    public function test_gateway_readiness_exception_is_hidden_and_disables_status_check(): void
    {
        $this->actingAs($this->finance);
        $this->gateway->throwOnReady = true;
        foreach ([false, true] as $enabled) {
            config(['subscriptions.checkout_enabled' => $enabled]);
            $this->get(route('operator.billing.index'))->assertOk()
                ->assertSee('Billing history')->assertDontSee('Check status')
                ->assertDontSee(route('operator.billing.check', ['attempt' => $this->attemptId]), false)
                ->assertDontSee('Request renewal')->assertDontSee('secret provider response');
            $this->post(route('operator.billing.check', ['attempt' => $this->attemptId]))
                ->assertStatus(503)->assertDontSee('secret provider response');
        }
        $this->assertSame(0, $this->gateway->starts);
        $this->assertSame(0, $this->gateway->verifies);
    }

    public function test_billing_and_status_checks_remain_role_and_tenant_scoped(): void
    {
        $other = Tenant::create(['name' => 'Workspace B', 'slug' => 'workspace-b', 'status' => 'active']);
        $otherFinance = User::factory()->create(['tenant_id' => $other->id]);
        $otherFinance->assignRole('operator_finance');
        URL::defaults(['tenant' => $other->slug]);
        $reference = DB::table('subscription_attempts')->where('id', $this->attemptId)->value('reference');
        $this->actingAs($otherFinance)->get(route('operator.billing.index'))->assertOk()->assertDontSee($reference)->assertDontSee('Check status');
        $this->post(route('operator.billing.check', ['attempt' => $this->attemptId]))->assertNotFound();
        $support = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $support->assignRole('operator_support');
        URL::defaults(['tenant' => $this->tenant->slug]);
        $this->actingAs($support)->get(route('operator.billing.index'))->assertForbidden();
        $this->post(route('operator.billing.check', ['attempt' => $this->attemptId]))->assertForbidden();
        $this->assertSame(0, $this->gateway->starts);
        $this->assertSame(0, $this->gateway->verifies);
    }

    public function test_invoice_detail_is_tenant_scoped_and_offers_printable_invoice_and_status_check(): void
    {
        $this->actingAs($this->finance)
            ->get(route('operator.billing.invoices.show', ['invoice' => $this->invoiceId]))
            ->assertOk()
            ->assertSee('Subscription invoice')
            ->assertSee('Print / Save PDF')
            ->assertSee('Monthly renewal of Status plan')
            ->assertSee('Check payment status')
            ->assertSee((string) DB::table('subscription_attempts')->where('id', $this->attemptId)->value('reference'));

        $other = Tenant::create(['name' => 'Workspace C', 'slug' => 'workspace-c', 'status' => 'active']);
        $otherFinance = User::factory()->create(['tenant_id' => $other->id]);
        $otherFinance->assignRole('operator_finance');
        URL::defaults(['tenant' => $other->slug]);

        $this->actingAs($otherFinance)
            ->get(route('operator.billing.invoices.show', ['invoice' => $this->invoiceId]))
            ->assertNotFound();
    }
}
