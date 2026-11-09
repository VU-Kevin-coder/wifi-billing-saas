<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SitesAndHotspotsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['operator_admin', 'operator_network', 'operator_finance', 'operator_support'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $this->beta = Tenant::create(['name' => 'Beta Wi-Fi', 'slug' => 'beta', 'status' => 'active']);
        $this->admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->admin->assignRole('operator_admin');

        URL::defaults(['tenant' => $this->acme->slug]);
    }

    public function test_sites_page_uses_real_tenant_scoped_router_session_and_revenue_data(): void
    {
        app(TenantContext::class)->runAs($this->acme, function (): void {
            $site = $this->acme->sites()->create(['name' => 'North Office', 'location' => 'North District']);
            $router = Router::create(['name' => 'North Router', 'site_id' => $site->id, 'site' => $site->name, 'status' => 'online']);
            $package = Package::create([
                'name' => 'Day Pass',
                'price' => 1500,
                'currency' => 'UGX',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 10,
                'device_limit' => 1,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $package->id,
                'batch_code' => 'NORTH-TEST',
                'quantity' => 1,
            ]);
            $voucher = Voucher::create([
                'site_id' => $site->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'NORTH-VOUCHER',
                'status' => 'redeemed',
                'redeemed_at' => now(),
            ]);
            $customer = Customer::create(['name' => 'North Customer']);
            Session::create([
                'site_id' => $site->id,
                'customer_id' => $customer->id,
                'router_id' => $router->id,
                'voucher_id' => $voucher->id,
                'package_id' => $package->id,
                'status' => 'active',
                'started_at' => now()->subMinutes(5),
                'expires_at' => now()->addHour(),
            ]);

        });

        app(TenantContext::class)->runAs($this->beta, function (): void {
            $site = $this->beta->sites()->create(['name' => 'Private Beta Site']);
            Router::create(['name' => 'Beta Router', 'site_id' => $site->id, 'status' => 'online']);
        });

        $response = $this->actingAs($this->admin)->get('http://acme.localhost/operator/sites');

        $response->assertOk()
            ->assertSee('North Office')
            ->assertDontSee('Private Beta Site')
            ->assertSee('Healthy')
            ->assertViewHas('operatingSites', 1)
            ->assertViewHas('customersCovered', 1)
            ->assertViewHas('liveConnections', 1);

        $response->assertSee('UGX 1,500');

        $export = $this->actingAs($this->admin)->get('http://acme.localhost/operator/sites/export');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('North Office', $export->streamedContent());
    }

    public function test_admin_can_create_site_and_other_tenant_site_is_not_visible(): void
    {
        $this->beta->sites()->create(['name' => 'Beta Only']);

        $this->actingAs($this->admin)
            ->post('http://acme.localhost/operator/sites', [
                'name' => 'South Office',
                'location' => 'South District',
            ])
            ->assertRedirect('http://acme.localhost/operator/sites');

        $this->assertDatabaseHas('sites', [
            'tenant_id' => $this->acme->id,
            'name' => 'South Office',
            'location' => 'South District',
        ]);
        $this->assertDatabaseMissing('sites', [
            'tenant_id' => $this->acme->id,
            'name' => 'Beta Only',
        ]);
    }

    public function test_router_can_only_be_assigned_to_a_site_in_its_own_tenant(): void
    {
        $ownSite = $this->acme->sites()->create(['name' => 'Own Site']);
        $foreignSite = $this->beta->sites()->create(['name' => 'Foreign Site']);

        $this->actingAs($this->admin)
            ->post('http://acme.localhost/operator/routers', [
                'name' => 'Router with foreign site',
                'site_id' => $foreignSite->id,
                'management_ip' => '10.0.0.10',
                'api_port' => 8728,
                'api_username' => 'router-api',
                'api_password' => 'password123',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasErrors('site_id');

        $this->actingAs($this->admin)
            ->post('http://acme.localhost/operator/routers', [
                'name' => 'Router with own site',
                'site_id' => $ownSite->id,
                'management_ip' => '10.0.0.11',
                'api_port' => 8728,
                'api_username' => 'router-api',
                'api_password' => 'password123',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('routers', [
            'tenant_id' => $this->acme->id,
            'name' => 'Router with own site',
            'site_id' => $ownSite->id,
            'site' => 'Own Site',
        ]);
    }

    public function test_portal_redemption_records_router_site_on_voucher_and_session(): void
    {
        $data = app(TenantContext::class)->runAs($this->acme, function (): array {
            $site = $this->acme->sites()->create(['name' => 'Portal Site']);
            $router = Router::create(['name' => 'Portal Router', 'site_id' => $site->id, 'site' => $site->name, 'status' => 'online']);
            $package = Package::create([
                'name' => 'Guest Pass',
                'price' => 500,
                'currency' => 'UGX',
                'duration_value' => 1,
                'duration_unit' => 'hours',
                'speed_limit_mbps' => 5,
                'device_limit' => 1,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $package->id,
                'batch_code' => 'PORTAL-TEST',
                'quantity' => 1,
            ]);
            $voucher = Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'PORTAL-SITE-01',
            ]);

            return compact('site', 'router', 'voucher');
        });

        $this->post('http://acme.localhost/portal/redeem', [
            'voucher_code' => 'PORTAL-SITE-01',
            'router_id' => $data['router']->id,
        ])->assertRedirect('http://acme.localhost/portal/success');

        $this->assertDatabaseHas('vouchers', [
            'id' => $data['voucher']->id,
            'site_id' => $data['site']->id,
            'status' => 'redeemed',
        ]);
        $this->assertDatabaseHas('sessions_log', [
            'voucher_id' => $data['voucher']->id,
            'site_id' => $data['site']->id,
            'router_id' => $data['router']->id,
        ]);
    }
}
