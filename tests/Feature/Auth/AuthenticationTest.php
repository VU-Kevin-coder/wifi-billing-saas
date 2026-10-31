<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_logging_in_from_main_domain_are_transferred_to_subdomain_dashboard(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');

        // 1. Post to login on the main domain (no subdomain)
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        // 2. Asserts redirect to signed SSO transfer on the tenant subdomain
        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('http://acme.localhost:8000/sso/verify', $targetUrl);
        $this->assertStringContainsString('signature=', $targetUrl);

        // 3. Following the signed SSO transfer establishes session and lands on dashboard
        $ssoResponse = $this->get($targetUrl);
        $ssoResponse->assertRedirect('http://acme.localhost:8000/operator/dashboard');

        // 4. Verify dashboard is accessible as authenticated operator
        $dashResponse = $this->get('http://acme.localhost:8000/operator/dashboard');
        $dashResponse->assertOk();
    }

    public function test_platform_admin_logging_in_redirects_to_platform_dashboard(): void
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('platform.dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
