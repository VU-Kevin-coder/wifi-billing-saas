<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperatorRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_role_cannot_manage_vouchers_routers_packages_or_security_settings(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'operator_support', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $support = User::factory()->create(['tenant_id' => $tenant->id]);
        $support->assignRole('operator_support');

        [$package, $router] = app(TenantContext::class)->runAs($tenant, function () {
            return [
                Package::create([
                    'name' => 'One hour',
                    'price' => 1000,
                    'currency' => 'UGX',
                    'duration_value' => 1,
                    'duration_unit' => 'hours',
                    'speed_limit_mbps' => 10,
                    'device_limit' => 1,
                    'is_published' => true,
                ]),
                Router::create([
                    'name' => 'Test router',
                    'management_ip' => '192.0.2.10',
                    'api_username' => 'router-api',
                    'api_password' => 'test-password',
                    'api_port' => 8728,
                ]),
            ];
        });

        $this->actingAs($support)
            ->post('http://acme.localhost/operator/vouchers', [])
            ->assertForbidden();
        $this->delete("http://acme.localhost/operator/routers/{$router->id}")
            ->assertForbidden();
        $this->put("http://acme.localhost/operator/packages/{$package->id}", [])
            ->assertForbidden();
        $this->put('http://acme.localhost/operator/settings/security', [])
            ->assertForbidden();
    }
}
