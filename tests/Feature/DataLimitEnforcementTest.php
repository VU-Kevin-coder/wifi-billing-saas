<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\SiteAgentRouterService;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use App\Support\DataLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class DataLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    // ── Parsing ─────────────────────────────────────────────────────────────

    /**
     * @dataProvider validLimits
     */
    public function test_valid_limits_become_bytes(string $text, int $bytes): void
    {
        $this->assertSame($bytes, DataLimit::bytes($text));
        $this->assertTrue(DataLimit::isValid($text));
    }

    public static function validLimits(): array
    {
        return [
            '1GB' => ['1GB', 1073741824],
            'lower case with space' => ['5 gb', 5368709120],
            '500 MB' => ['500 MB', 524288000],
            'decimal' => ['2.5GB', 2684354560],
            'kilobytes' => ['100KB', 102400],
            'terabytes' => ['1TB', 1099511627776],
            'spelled bytes' => ['10 Gbytes', 10737418240],
        ];
    }

    /**
     * @dataProvider unlimitedValues
     */
    public function test_unlimited_values_mean_no_cap(?string $text): void
    {
        $this->assertNull(DataLimit::bytes($text));
        $this->assertTrue(DataLimit::isUnlimited($text));
    }

    public static function unlimitedValues(): array
    {
        return [[null], [''], ['  '], ['Unlimited'], ['unlimited data'], ['No limit'], ['none']];
    }

    /**
     * @dataProvider invalidLimits
     */
    public function test_unclear_limits_are_rejected_not_guessed(string $text): void
    {
        $this->assertFalse(DataLimit::isValid($text));
        $this->expectException(\InvalidArgumentException::class);
        DataLimit::bytes($text);
    }

    public static function invalidLimits(): array
    {
        return [['lots'], ['5'], ['GB'], ['-1GB'], ['1 GB per day'], ['0GB'], ['1,5GB'], ['5 Gigabytes']];
    }

    // ── Saving packages ─────────────────────────────────────────────────────

    private function packageForm(string $dataLimit): array
    {
        return [
            'name' => 'Weekly', 'price' => 5000, 'duration_value' => 7, 'duration_unit' => 'days',
            'speed_limit_mbps' => 10, 'device_limit' => 1, 'data_limit' => $dataLimit,
        ];
    }

    public function test_operators_can_only_save_limits_the_router_can_enforce(): void
    {
        Role::findOrCreate('operator_admin', 'web');
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('operator_admin');
        URL::defaults(['tenant' => 'acme']);

        $this->actingAs($admin)->post(route('operator.packages.store'), $this->packageForm('lots'))
            ->assertSessionHasErrors('data_limit');
        $this->actingAs($admin)->post(route('operator.packages.store'), $this->packageForm('5'))
            ->assertSessionHasErrors('data_limit');

        $this->actingAs($admin)->post(route('operator.packages.store'), $this->packageForm('5GB'))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('operator.packages.store'), array_merge($this->packageForm('Unlimited'), ['name' => 'Open']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Package::withoutTenancy()->where('tenant_id', $tenant->id)->count());
    }

    // ── What the router is told ─────────────────────────────────────────────

    private function activatedCommand(?string $dataLimit): SiteAgentCommand
    {
        config(['site_agent.required' => true]);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'status' => 'active']);
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'North']);
        Router::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'R1', 'status' => 'online']);
        SiteAgent::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'Agent', 'token_hash' => hash('sha256', uniqid()), 'last_seen_at' => now()]);
        $package = Package::create([
            'tenant_id' => $tenant->id, 'name' => 'Pack', 'price' => 1000, 'currency' => 'UGX', 'duration_value' => 1,
            'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1, 'data_limit' => $dataLimit,
        ]);
        $batch = VoucherBatch::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'batch_code' => 'B', 'quantity' => 1]);
        $voucher = Voucher::create(['tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $package->id, 'code' => 'DL-'.uniqid(), 'status' => 'unused']);

        app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));

        return SiteAgentCommand::query()->where('tenant_id', $tenant->id)->where('operation', 'provision_user')->sole();
    }

    public function test_the_provision_command_carries_the_byte_cap(): void
    {
        $this->assertSame(1073741824, $this->activatedCommand('1GB')->payload['limit_bytes_total']);
    }

    public function test_unlimited_packages_send_no_cap(): void
    {
        $this->assertNull($this->activatedCommand('Unlimited')->payload['limit_bytes_total']);
        $this->assertNull($this->activatedCommand(null)->payload['limit_bytes_total']);
    }

    public function test_an_unparseable_legacy_value_does_not_block_activation_but_is_logged(): void
    {
        Log::spy();

        $command = $this->activatedCommand('five gigs or so');

        $this->assertNull($command->payload['limit_bytes_total']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'unparseable data limit'))->once();
    }

    public function test_the_agent_applies_the_cap_to_the_router_user(): void
    {
        config(['site_agent.routers.7' => ['host' => '10.0.0.1', 'username' => 'api', 'password' => 'secret']]);
        $hotspot = Mockery::mock();
        $hotspot->shouldReceive('getUser')->once()->with('VOUCHER-1')->andReturn(null);
        $hotspot->shouldReceive('createUser')->once()->with(Mockery::on(
            fn (array $data) => $data['limit-bytes-total'] === 1073741824 && $data['name'] === 'VOUCHER-1'
        ));
        $router = Mockery::mock();
        $router->shouldReceive('hotspot')->andReturn($hotspot);
        MikroTik::shouldReceive('router')->andReturn($router);

        app(SiteAgentRouterService::class)->execute(7, 'provision_user', [
            'username' => 'VOUCHER-1', 'limit_bytes_total' => 1073741824,
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_no_cap_means_the_router_user_has_no_byte_limit_field(): void
    {
        config(['site_agent.routers.7' => ['host' => '10.0.0.1', 'username' => 'api', 'password' => 'secret']]);
        $hotspot = Mockery::mock();
        $hotspot->shouldReceive('getUser')->andReturn(null);
        $hotspot->shouldReceive('createUser')->once()->with(Mockery::on(fn (array $data) => ! array_key_exists('limit-bytes-total', $data)));
        $router = Mockery::mock();
        $router->shouldReceive('hotspot')->andReturn($hotspot);
        MikroTik::shouldReceive('router')->andReturn($router);

        app(SiteAgentRouterService::class)->execute(7, 'provision_user', ['username' => 'VOUCHER-2', 'limit_bytes_total' => null]);

        $this->addToAssertionCount(1);
    }
}
