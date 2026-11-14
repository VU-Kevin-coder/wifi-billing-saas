<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PackageIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin']);

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
    }

    public function test_packages_are_isolated_between_tenants(): void
    {
        $context = app(TenantContext::class);

        // Create package for Acme
        $context->runAs($this->acme, function () {
            Package::create([
                'name' => 'Acme Daily',
                'price' => 1000,
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 5,
                'device_limit' => 1,
            ]);
        });

        // Create package for Beta
        $context->runAs($this->beta, function () {
            Package::create([
                'name' => 'Beta Weekly',
                'price' => 5000,
                'duration_value' => 7,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 10,
                'device_limit' => 2,
            ]);
        });

        // As Acme, only see Acme package
        $context->runAs($this->acme, function () {
            $packages = Package::all();
            $this->assertCount(1, $packages);
            $this->assertEquals('Acme Daily', $packages->first()->name);
        });

        // As Beta, only see Beta package
        $context->runAs($this->beta, function () {
            $packages = Package::all();
            $this->assertCount(1, $packages);
            $this->assertEquals('Beta Weekly', $packages->first()->name);
        });
    }

    public function test_package_expiry_supports_all_duration_units(): void
    {
        $startedAt = Carbon::parse('2026-01-15 12:00:00');
        $durations = [
            'minutes' => 'addMinutes',
            'hours' => 'addHours',
            'days' => 'addDays',
            'weeks' => 'addWeeks',
            'months' => 'addMonths',
            'years' => 'addYears',
        ];

        foreach ($durations as $unit => $addMethod) {
            $package = new Package([
                'duration_value' => 2,
                'duration_unit' => $unit,
            ]);

            $this->assertSame(rtrim($unit, 's'), $package->duration_unit_singular);
            $this->assertEquals($startedAt->copy()->$addMethod(2), $package->expiresAt($startedAt));
        }
    }

    public function test_new_packages_use_the_configured_platform_currency(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $admin->assignRole('operator_admin');
        PlatformSetting::set('currency', 'KES');

        $this->actingAs($admin)
            ->post('http://acme.localhost:8000/operator/packages', [
                'name' => 'Daily KES',
                'price' => 100,
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 5,
                'device_limit' => 1,
                'is_published' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('packages', [
            'tenant_id' => $this->acme->id,
            'name' => 'Daily KES',
            'currency' => 'KES',
        ]);
    }

    public function test_package_controls_reject_values_above_the_platform_limits(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $admin->assignRole('operator_admin');
        PlatformSetting::set('package.max_speed', 10);
        PlatformSetting::set('package.max_devices', 2);

        $this->actingAs($admin)
            ->post('http://acme.localhost:8000/operator/packages', [
                'name' => 'Too fast',
                'price' => 100,
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 11,
                'device_limit' => 2,
            ])
            ->assertSessionHasErrors('speed_limit_mbps');

        PlatformSetting::set('package.data_caps', false);
        $this->actingAs($admin)
            ->post('http://acme.localhost:8000/operator/packages', [
                'name' => 'Capped',
                'price' => 100,
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 5,
                'device_limit' => 2,
                'data_limit' => '2GB',
            ])
            ->assertSessionHasErrors('data_limit');

        $this->assertSame(0, Package::withoutTenancy()->where('tenant_id', $this->acme->id)->count());
    }

    public function test_operator_can_view_packages_page_and_dashboard(): void
    {
        URL::defaults(['tenant' => $this->acme->slug]);

        $admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $admin->assignRole('operator_admin');

        // Test dashboard renders with the package navigation link
        $dashResponse = $this->actingAs($admin)->get('http://acme.localhost:8000/operator/dashboard');
        $dashResponse->assertOk();
        $dashResponse->assertSee('Internet packages');

        // Test packages index renders
        $indexResponse = $this->actingAs($admin)->get('http://acme.localhost:8000/operator/packages');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Internet Packages');
        $indexResponse->assertSee('Published packages');
    }
}
