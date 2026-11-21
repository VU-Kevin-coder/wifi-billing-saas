<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PathTenantRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $previousMode = getenv('TENANT_ROUTING_MODE');
        $previousAppUrl = getenv('APP_URL');
        putenv('TENANT_ROUTING_MODE=path');
        $_ENV['TENANT_ROUTING_MODE'] = 'path';
        $_SERVER['TENANT_ROUTING_MODE'] = 'path';
        putenv('APP_URL=https://tunnel.example.test');
        $_ENV['APP_URL'] = 'https://tunnel.example.test';
        $_SERVER['APP_URL'] = 'https://tunnel.example.test';

        try {
            return parent::createApplication();
        } finally {
            if ($previousMode === false) {
                putenv('TENANT_ROUTING_MODE');
                unset($_ENV['TENANT_ROUTING_MODE'], $_SERVER['TENANT_ROUTING_MODE']);
            } else {
                putenv('TENANT_ROUTING_MODE='.$previousMode);
                $_ENV['TENANT_ROUTING_MODE'] = $previousMode;
                $_SERVER['TENANT_ROUTING_MODE'] = $previousMode;
            }

            if ($previousAppUrl === false) {
                putenv('APP_URL');
                unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
            } else {
                putenv('APP_URL='.$previousAppUrl);
                $_ENV['APP_URL'] = $previousAppUrl;
                $_SERVER['APP_URL'] = $previousAppUrl;
            }
        }
    }

    public function test_workspace_urls_use_a_tenant_path_on_the_configured_https_host(): void
    {
        config(['app.url' => 'https://tunnel.example.test']);
        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);

        $this->assertSame(
            'https://tunnel.example.test/build/assets/app.css',
            asset('build/assets/app.css'),
        );
        $this->assertSame('/acme/operator/dashboard', route('operator.dashboard', ['tenant' => 'acme'], false));
        $this->assertSame('/acme/portal', route('portal.index', ['tenant' => 'acme'], false));
        $this->assertSame('https://tunnel.example.test/acme/operator/dashboard', $tenant->url('operator/dashboard'));
        $this->assertStringStartsWith('https://', route('login'));

        $this->get('/acme/portal')->assertOk();
    }

    public function test_workspace_path_selects_and_enforces_the_requested_tenant(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $beta = Tenant::create(['name' => 'Beta Wi-Fi', 'slug' => 'beta', 'status' => 'active']);
        $acmeAdmin = User::factory()->create(['tenant_id' => $acme->id]);
        $acmeAdmin->assignRole('operator_admin');
        $betaAdmin = User::factory()->create(['tenant_id' => $beta->id]);
        $betaAdmin->assignRole('operator_admin');

        $this->actingAs($acmeAdmin)
            ->get('/acme/operator/dashboard')
            ->assertOk();

        $this->actingAs($betaAdmin)
            ->get('/acme/operator/dashboard')
            ->assertForbidden();
    }

    public function test_operator_login_redirects_to_the_tenant_path(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->post('/login', ['email' => $operator->email, 'password' => 'password'])
            ->assertRedirect(route('operator.dashboard', ['tenant' => 'acme']));
    }
}
