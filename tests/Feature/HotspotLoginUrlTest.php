<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Router;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\HotspotLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HotspotLoginUrlTest extends TestCase
{
    use RefreshDatabase;

    private function router(Tenant $tenant, ?array $hotspotConfig, string $name = 'R1'): Router
    {
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => $name.' site']);

        return Router::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => $name,
            'status' => 'online',
            'hotspot_config' => $hotspotConfig,
        ]);
    }

    // ── URL building ────────────────────────────────────────────────────────

    public function test_url_uses_the_routers_own_gateway_and_encodes_the_code(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $router = $this->router($tenant, ['gateway' => '192.168.88.1']);

        $this->assertSame('http://192.168.88.1/login?username=AB%20%26C', HotspotLogin::url($router, 'AB &C'));
    }

    #[DataProvider('untrustedGateways')]
    public function test_a_gateway_that_is_not_a_plain_ipv4_address_is_never_used(mixed $gateway): void
    {
        config(['hotspot.default_gateway' => null]);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $router = $this->router($tenant, ['gateway' => $gateway]);

        $this->assertNull(HotspotLogin::url($router, 'CODE'));
    }

    public static function untrustedGateways(): array
    {
        return [
            'hostname' => ['evil.example.com'],
            'url' => ['http://evil.example.com'],
            'ip with path' => ['10.0.0.1/evil'],
            'ip with credentials' => ['user@10.0.0.1'],
            'ipv6' => ['::1'],
            'empty' => [''],
            'not a string' => [['10.0.0.1']],
        ];
    }

    public function test_default_gateway_is_used_only_when_the_router_has_none_and_it_is_valid(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $noGateway = $this->router($tenant, null);

        config(['hotspot.default_gateway' => '10.20.30.1']);
        $this->assertSame('http://10.20.30.1/login?username=C', HotspotLogin::url($noGateway, 'C'));
        $this->assertSame('http://10.20.30.1/login?username=C', HotspotLogin::url(null, 'C'));

        config(['hotspot.default_gateway' => 'not-an-ip']);
        $this->assertNull(HotspotLogin::url($noGateway, 'C'));

        config(['hotspot.default_gateway' => null]);
        $this->assertNull(HotspotLogin::url(null, 'C'));
    }

    public function test_the_routers_gateway_wins_over_the_platform_default(): void
    {
        config(['hotspot.default_gateway' => '10.20.30.1']);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);

        $this->assertSame(
            'http://172.16.0.1/login?username=C',
            HotspotLogin::url($this->router($tenant, ['gateway' => '172.16.0.1']), 'C')
        );
    }

    // ── End to end through the portal ───────────────────────────────────────

    private function voucherFor(Tenant $tenant, string $code): Voucher
    {
        $package = Package::create([
            'tenant_id' => $tenant->id, 'name' => 'Guest', 'price' => 500, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id, 'batch_code' => 'B-'.$code, 'quantity' => 1,
        ]);

        return Voucher::create([
            'tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id,
            'package_id' => $package->id, 'code' => $code, 'status' => 'unused',
        ]);
    }

    public function test_each_tenant_is_sent_to_its_own_hotspot_gateway_after_redeeming(): void
    {
        $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $routerA = $this->router($acme, ['gateway' => '10.10.0.1'], 'RA');
        $routerB = $this->router($beta, ['gateway' => '192.168.50.1'], 'RB');
        $this->voucherFor($acme, 'ACME-0001');
        $this->voucherFor($beta, 'BETA-0001');

        $this->post('http://acme.localhost/portal/redeem', ['voucher_code' => 'ACME-0001', 'router_id' => $routerA->id])
            ->assertSessionHas('mikrotik_url', 'http://10.10.0.1/login?username=ACME-0001');

        $this->post('http://beta.localhost/portal/redeem', ['voucher_code' => 'BETA-0001', 'router_id' => $routerB->id])
            ->assertSessionHas('mikrotik_url', 'http://192.168.50.1/login?username=BETA-0001');
    }

    public function test_the_login_url_follows_the_router_the_customer_used_not_another_router_of_the_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->router($tenant, ['gateway' => '10.10.0.1'], 'First');
        $second = $this->router($tenant, ['gateway' => '10.99.0.1'], 'Second');
        $voucher = $this->voucherFor($tenant, 'ACME-0002');

        $this->post('http://acme.localhost/portal/redeem', ['voucher_code' => 'ACME-0002', 'router_id' => $second->id])
            ->assertSessionHas('mikrotik_url', 'http://10.99.0.1/login?username=ACME-0002');

        $this->assertSame('http://10.99.0.1/login?username=ACME-0002', HotspotLogin::forVoucher($voucher->fresh()));
    }

    public function test_a_voucher_without_a_session_gets_no_link_when_there_is_no_default(): void
    {
        config(['hotspot.default_gateway' => null]);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

        $this->assertNull(HotspotLogin::forVoucher($this->voucherFor($tenant, 'ACME-0003')));
    }

    // ── Gateway input validation ────────────────────────────────────────────

    public function test_operators_can_only_save_an_ipv4_gateway_for_a_router(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        URL::defaults(['tenant' => 'acme']);

        $this->actingAs($user)
            ->post(route('operator.routers.store'), ['name' => 'Bad', 'gateway_ip' => 'evil.example.com'])
            ->assertSessionHasErrors('gateway_ip');

        $this->actingAs($user)
            ->post(route('operator.routers.store'), ['name' => 'Good', 'gateway_ip' => '192.168.88.1'])
            ->assertSessionHasNoErrors();

        $stored = Router::withoutTenancy()->where('name', 'Good')->firstOrFail();
        $this->assertSame('192.168.88.1', $stored->hotspot_config['gateway']);
    }

    public function test_no_hardcoded_gateway_address_remains_in_application_code(): void
    {
        foreach (['app/Http/Controllers/PortalController.php', 'routes/web.php'] as $file) {
            $this->assertStringNotContainsString('10.5.50.1', file_get_contents(base_path($file)), $file);
        }
    }

    // ── Editing the gateway of an existing router ───────────────────────────

    private function operatorFor(Tenant $tenant, string $role = 'operator_admin'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);
        URL::defaults(['tenant' => $tenant->slug]);

        return $user;
    }

    public function test_an_operator_can_correct_a_routers_gateway_and_it_is_audited(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $router = $this->router($tenant, ['gateway' => '10.10.0.1', 'dns' => '8.8.8.8']);
        $user = $this->operatorFor($tenant);

        $this->actingAs($user)
            ->patch(route('operator.routers.gateway', $router->id), ['gateway_ip' => '10.5.50.1'])
            ->assertSessionHasNoErrors();

        $fresh = $router->fresh();
        $this->assertSame('10.5.50.1', $fresh->hotspot_config['gateway']);
        $this->assertSame('8.8.8.8', $fresh->hotspot_config['dns'], 'other hotspot settings are preserved');

        $log = AuditLog::where('action', 'router.gateway_changed')->firstOrFail();
        $this->assertSame('10.10.0.1', $log->meta['from']);
        $this->assertSame('10.5.50.1', $log->meta['to']);
    }

    public function test_an_invalid_gateway_is_rejected_when_editing(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $router = $this->router($tenant, ['gateway' => '10.10.0.1']);
        $user = $this->operatorFor($tenant);

        $this->actingAs($user)
            ->patch(route('operator.routers.gateway', $router->id), ['gateway_ip' => 'evil.example.com'])
            ->assertSessionHasErrors('gateway_ip');

        $this->assertSame('10.10.0.1', $router->fresh()->hotspot_config['gateway']);
    }

    public function test_a_tenant_cannot_change_another_tenants_router_gateway(): void
    {
        $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $betaRouter = $this->router($beta, ['gateway' => '10.10.0.1']);
        $user = $this->operatorFor($acme);

        $this->actingAs($user)
            ->patch(route('operator.routers.gateway', $betaRouter->id), ['gateway_ip' => '10.5.50.1'])
            ->assertNotFound();

        $this->assertSame('10.10.0.1', $betaRouter->fresh()->hotspot_config['gateway']);
    }

    public function test_finance_staff_cannot_change_a_gateway(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $router = $this->router($tenant, ['gateway' => '10.10.0.1']);
        $user = $this->operatorFor($tenant, 'operator_finance');

        $this->actingAs($user)
            ->patch(route('operator.routers.gateway', $router->id), ['gateway_ip' => '10.5.50.1'])
            ->assertForbidden();
    }
}
