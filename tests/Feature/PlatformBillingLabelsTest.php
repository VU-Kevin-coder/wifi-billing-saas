<?php

namespace Tests\Feature;

use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformBillingLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_displays_recorded_configuration_not_fabricated_payments(): void
    {
        Http::preventStrayRequests();
        Role::findOrCreate('platform_admin', 'web');
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $plan = PlatformPlan::create(['name' => 'Example', 'price' => 999000, 'trial_days' => 7, 'max_customers' => 10, 'max_routers' => 2]);
        Tenant::create(['name' => 'Active Example', 'slug' => 'active-example', 'status' => 'active', 'platform_plan_id' => $plan->id, 'plan_fee' => 123000, 'subscription_status' => 'active', 'subscription_ends_at' => '2027-03-02', 'trial_ends_at' => '2027-02-01']);
        Tenant::create(['name' => 'Trial Example', 'slug' => 'trial-example', 'status' => 'active', 'plan_fee' => 27000, 'subscription_status' => 'trial']);
        Tenant::create(['name' => 'Past Example', 'slug' => 'past-example', 'status' => 'active', 'plan_fee' => 35000, 'subscription_status' => 'past_due']);
        Tenant::create(['name' => 'Unknown Example', 'slug' => 'unknown-example', 'status' => 'active', 'plan_fee' => 0, 'subscription_status' => 'unrecognized']);

        $response = $this->actingAs($admin)->get(route('platform.billing.index'));
        $response->assertOk()
            ->assertSee('Subscription checkout and invoices are not connected.')
            ->assertSee('Subscription status is not proof of payment.')
            ->assertSee('Configured fees are not receipts or collected revenue.')
            ->assertSee('Configured fees: active status')
            ->assertSee('UGX 123,000')
            ->assertSee('UGX 35,000')
            ->assertSee('not verified debt')
            ->assertSee('Subscription ends: 02 Mar 2027')
            ->assertSee('Trial ends: 01 Feb 2027')
            ->assertSee('No dates recorded')
            ->assertSee('unrecognized')
            ->assertDontSee('Active (Paid)')
            ->assertDontSee('14-Day Trial')
            ->assertDontSee('14-day free trial')
            ->assertDontSee('Invoice Ref')
            ->assertDontSee('INV-')
            ->assertDontSee('All accounts up to date');
        Http::assertNothingSent();
    }
}
