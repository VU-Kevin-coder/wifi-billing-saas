<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffAndImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected User $platformAdmin;

    protected User $acmeAdmin;

    protected User $acmeFinance;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_finance', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_support', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_network', 'guard_name' => 'web']);

        $this->acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $this->beta = Tenant::create(['name' => 'Beta Networks', 'slug' => 'beta', 'status' => 'active']);

        $this->platformAdmin = User::factory()->create(['tenant_id' => null]);
        $this->platformAdmin->assignRole('platform_admin');

        $this->acmeAdmin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->acmeAdmin->assignRole('operator_admin');

        $this->acmeFinance = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->acmeFinance->assignRole('operator_finance');

        URL::defaults(['tenant' => $this->acme->slug]);
    }

    public function test_operator_admin_can_view_staff_list(): void
    {
        $betaAdmin = User::factory()->create([
            'name' => 'Beta Admin User',
            'tenant_id' => $this->beta->id,
        ]);
        $betaAdmin->assignRole('operator_admin');

        $response = $this->actingAs($this->acmeAdmin)
            ->get(route('operator.staff.index'));

        $response->assertOk();
        $response->assertSee($this->acmeAdmin->name);
        $response->assertSee($this->acmeFinance->name);
        $response->assertDontSee('Beta Admin User');
        $response->assertDontSee($this->platformAdmin->name);
    }

    public function test_operator_admin_can_create_staff_member(): void
    {
        $response = $this->actingAs($this->acmeAdmin)
            ->post(route('operator.staff.store'), [
                'name' => 'Support Tech',
                'email' => 'tech@acme.test',
                'phone' => '+256700123456',
                'role' => 'operator_support',
            ]);

        $response->assertRedirect(route('operator.team.index'));
        $this->assertDatabaseHas('users', [
            'tenant_id' => $this->acme->id,
            'name' => 'Support Tech',
            'email' => 'tech@acme.test',
            'phone' => '+256700123456',
        ]);

        $created = User::where('email', 'tech@acme.test')->first();
        $this->assertTrue($created->hasRole('operator_support'));
    }

    public function test_new_staff_do_not_get_a_known_password_and_are_invited_by_email(): void
    {
        Notification::fake();

        $this->actingAs($this->acmeAdmin)
            ->post(route('operator.staff.store'), [
                'name' => 'New Hire',
                'email' => 'hire@acme.test',
                'role' => 'operator_admin',
            ])
            ->assertRedirect(route('operator.team.index'));

        $created = User::where('email', 'hire@acme.test')->firstOrFail();

        $this->assertFalse(Hash::check('password', $created->password));
        $this->assertNull($created->email_verified_at);
        $this->assertTrue($created->hasRole('operator_admin'));
        Notification::assertSentTo($created, ResetPassword::class);
    }

    public function test_finance_user_cannot_view_staff_list(): void
    {
        $response = $this->actingAs($this->acmeFinance)
            ->get(route('operator.staff.index'));

        $response->assertForbidden();
    }

    public function test_finance_user_cannot_view_create_staff_form(): void
    {
        $response = $this->actingAs($this->acmeFinance)
            ->get(route('operator.staff.create'));

        $response->assertForbidden();
    }

    public function test_finance_user_cannot_create_staff(): void
    {
        $response = $this->actingAs($this->acmeFinance)
            ->post(route('operator.staff.store'), [
                'name' => 'Unauthorized User',
                'email' => 'unauth@acme.test',
                'role' => 'operator_finance',
            ]);

        $response->assertForbidden();
    }

    public function test_finance_user_does_not_see_staff_link_in_navigation(): void
    {
        $response = $this->actingAs($this->acmeFinance)
            ->get(route('operator.dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('operator.staff.index'));
    }

    public function test_support_and_network_roles_cannot_access_staff(): void
    {
        $support = User::factory()->create(['tenant_id' => $this->acme->id]);
        $support->assignRole('operator_support');

        $network = User::factory()->create(['tenant_id' => $this->acme->id]);
        $network->assignRole('operator_network');

        $this->actingAs($support)->get(route('operator.staff.index'))->assertForbidden();
        $this->actingAs($network)->get(route('operator.staff.index'))->assertForbidden();
    }

    public function test_platform_admin_can_impersonate_and_stop_impersonating(): void
    {
        // 1. Platform admin switches into Acme
        $response = $this->actingAs($this->platformAdmin)
            ->post(route('platform.impersonate', $this->acme));

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('http://acme.localhost:8000/sso/verify', $targetUrl);
        $this->assertStringContainsString('token=', $targetUrl);

        // 2. Following the SSO transfer establishes impersonation session on the tenant subdomain
        $ssoResponse = $this->get($targetUrl);
        $ssoResponse->assertRedirect('http://acme.localhost:8000/operator/dashboard');
        $ssoResponse->assertSessionHas('impersonate_tenant', $this->acme->id);

        // 3. Visiting operator dashboard with impersonation session
        $dashResponse = $this->actingAs($this->platformAdmin)
            ->withSession(['impersonate_tenant' => $this->acme->id])
            ->get(route('operator.dashboard'));

        $dashResponse->assertOk();
        $dashResponse->assertSee('Acme Wi-Fi');
        $dashResponse->assertSee('Exit Impersonation');

        // 3. Stop impersonating
        $exitResponse = $this->actingAs($this->platformAdmin)
            ->withSession(['impersonate_tenant' => $this->acme->id])
            ->post(route('platform.stop-impersonate'));

        $exitResponse->assertRedirect(route('platform.dashboard'));
        $exitResponse->assertSessionMissing('impersonate_tenant');
    }
}
