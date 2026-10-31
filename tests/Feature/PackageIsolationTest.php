<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_operator_can_view_packages_page_and_dashboard(): void
    {
        \Illuminate\Support\Facades\URL::defaults(['tenant' => $this->acme->slug]);

        $admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $admin->assignRole('operator_admin');

        // Test dashboard renders with Packages link
        $dashResponse = $this->actingAs($admin)->get('http://acme.localhost:8000/operator/dashboard');
        $dashResponse->assertOk();
        $dashResponse->assertSee('Manage Packages');

        // Test packages index renders
        $indexResponse = $this->actingAs($admin)->get('http://acme.localhost:8000/operator/packages');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Internet Packages');
        $indexResponse->assertSee('Published packages');
    }
}
