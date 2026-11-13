<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
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

    public function test_login_throttle_attempts_and_decay_follow_platform_policy(): void
    {
        Policy::set('security.login_throttle_attempts', 2);
        Policy::set('security.login_throttle_decay', 1);
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('user@example.test|127.0.0.1');

        User::factory()->create(['email' => 'user@example.test']);

        $this->post('/login', ['email' => 'user@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertEquals(2, RateLimiter::attempts('user@example.test|127.0.0.1'));
        $this->assertEquals(2, RateLimiter::attempts('login:ip:127.0.0.1'));
        $this->assertGreaterThan(0, RateLimiter::availableIn('user@example.test|127.0.0.1'));
    }

    public function test_login_throttle_limits_attempts_per_ip_across_different_emails(): void
    {
        Policy::set('security.login_throttle_attempts', 6);
        Policy::set('security.login_throttle_ip_attempts', 2);
        Policy::set('security.login_throttle_decay', 1);
        RateLimiter::clear('login:ip:127.0.0.1');

        $this->post('/login', ['email' => 'first@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'second@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'third@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertEquals(2, RateLimiter::attempts('login:ip:127.0.0.1'));
    }

    public function test_password_defaults_follow_the_platform_minimum_and_breach_setting(): void
    {
        PlatformSetting::set('security.password_min', 10);
        PlatformSetting::set('security.password_breach', false);
        $underMinimum = Validator::make(
            ['password' => 'Pass12345'],
            ['password' => Password::defaults()],
        );
        $atMinimum = Validator::make(
            ['password' => 'Pass123456'],
            ['password' => Password::defaults()],
        );

        $this->assertTrue($underMinimum->fails());
        $this->assertTrue($atMinimum->passes());
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
