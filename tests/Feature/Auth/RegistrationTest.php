<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RegistrationEmailCode;
use App\Notifications\WelcomeToHamnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200)
            ->assertSee('Verify email')
            ->assertSee('Email verification code');
    }

    public function test_new_users_can_register_and_create_tenant(): void
    {
        Notification::fake();
        $this->seedEmailCode('owner@swift.test');

        $response = $this->post('/register', [
            'business_name' => 'Swift Wi-Fi',
            'subdomain' => 'swift',
            'name' => 'Swift Owner',
            'email' => 'owner@swift.test',
            'email_code' => '123456',
            'password' => 'Pass12345678',
            'password_confirmation' => 'Pass12345678',
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
        $this->assertNotNull($user->email_verified_at);
        Notification::assertSentTo($user, WelcomeToHamnet::class);
    }

    public function test_registration_sends_email_code_before_creating_an_account(): void
    {
        Notification::fake();

        $response = $this->post('/register/email-code', ['email' => 'owner@swift.test']);

        $response->assertRedirect()
            ->assertSessionHas('email_code_sent');
        Notification::assertSentOnDemand(RegistrationEmailCode::class);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_a_valid_email_code(): void
    {
        $response = $this->from('/register')->post('/register', [
            'business_name' => 'Swift Wi-Fi',
            'subdomain' => 'swift',
            'name' => 'Swift Owner',
            'email' => 'owner@swift.test',
            'email_code' => '000000',
            'password' => 'Pass12345678',
            'password_confirmation' => 'Pass12345678',
        ]);

        $response->assertRedirect('/register')
            ->assertSessionHasErrors('email_code');
        $this->assertDatabaseCount('tenants', 0);
    }

    private function seedEmailCode(string $email): void
    {
        Cache::put(
            'registration-email-code:'.hash('sha256', $email),
            Hash::make('123456'),
            now()->addMinutes(10),
        );
    }
}
