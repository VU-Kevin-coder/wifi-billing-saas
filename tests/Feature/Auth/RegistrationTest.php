<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_create_tenant(): void
    {
        $response = $this->post('/register', [
            'business_name' => 'Swift Wi-Fi',
            'subdomain' => 'swift',
            'name' => 'Swift Owner',
            'email' => 'owner@swift.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Check redirect to signed SSO transfer on the newly created tenant subdomain
        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('http://swift.localhost:8000/sso/verify', $targetUrl);
        $this->assertStringContainsString('signature=', $targetUrl);

        // Follow SSO transfer to authenticate on the new subdomain
        $ssoResponse = $this->get($targetUrl);
        $ssoResponse->assertRedirect('http://swift.localhost:8000/operator/dashboard');

        // Confirm DB state
        $this->assertDatabaseHas('tenants', [
            'name' => 'Swift Wi-Fi',
            'slug' => 'swift',
            'status' => 'active',
        ]);

        $user = User::where('email', 'owner@swift.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->tenant_id);
        $this->assertTrue($user->hasRole('operator_admin'));
    }
}
