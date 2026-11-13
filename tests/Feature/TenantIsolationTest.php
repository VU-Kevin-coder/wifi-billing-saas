<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin']);
        Role::create(['name' => 'platform_admin']);

        $this->acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $this->beta = Tenant::create(['name' => 'Beta Networks', 'slug' => 'beta']);
    }

    public function test_models_are_scoped_to_tenant(): void
    {
        $context = app(TenantContext::class);

        // Create data in Acme's context
        $context->runAs($this->acme, function () {
            Customer::create(['name' => 'John Doe']);
            Customer::create(['name' => 'Jane Smith']);
        });

        // Create data in Beta's context
        $context->runAs($this->beta, function () {
            Customer::create(['name' => 'Bob Beta']);
        });

        // As Acme, we should only see Acme's 2 customers
        $context->runAs($this->acme, function () {
            $this->assertCount(2, Customer::all());
            $this->assertEquals(['John Doe', 'Jane Smith'], Customer::pluck('name')->toArray());
        });

        // As Beta, we should only see Beta's 1 customer
        $context->runAs($this->beta, function () {
            $this->assertCount(1, Customer::all());
            $this->assertEquals(['Bob Beta'], Customer::pluck('name')->toArray());
        });

        // Without tenant context, tenant-owned data fails closed.
        $context->forget();
        $this->assertCount(0, Customer::all());
        $this->assertCount(3, Customer::withoutTenancy()->get());
    }

    public function test_tenant_id_is_auto_populated_on_create(): void
    {
        $context = app(TenantContext::class);

        $customer = $context->runAs($this->acme, function () {
            return Customer::create(['name' => 'Auto Tenant Test']);
        });

        $this->assertEquals($this->acme->id, $customer->tenant_id);
    }

    public function test_tenant_urls_use_the_configured_application_host_and_scheme(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://billing.example.test:8443',
            'tenancy.base_domain' => 'wifi.example.test',
        ]);

        $this->assertSame('acme.wifi.example.test:8443', $this->acme->host());
        $this->assertSame(
            'https://acme.wifi.example.test:8443/operator',
            $this->acme->url('/operator'),
        );
    }

    public function test_operator_cannot_access_platform_area(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->acme->id]);
        $user->assignRole('operator_admin');

        $this->actingAs($user)
            ->get('/platform/dashboard')
            ->assertForbidden(); // 403
    }
}
