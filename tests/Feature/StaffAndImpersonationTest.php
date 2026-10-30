<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'role' => 'operator_support',
            ]);

        $response->assertRedirect(route('operator.staff.index'));
        $this->assertDatabaseHas('users', [
            'tenant_id' => $this->acme->id,
            'name' => 'Support Tech',
            'email' => 'tech@acme.test',
        ]);

        $created = User::where('email', 'tech@acme.test')->first();
        $this->assertTrue($created->hasRole('operator_support'));
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

    public function test_platform_admin_can_impersonate_and_stop_impersonating(): void
    {
        // 1. Platform admin switches into Acme
        $response = $this->actingAs($this->platformAdmin)
            ->get(route('platform.impersonate', $this->acme));

        $response->assertRedirect(route('operator.dashboard'));
        $response->assertSessionHas('impersonate_tenant', $this->acme->id);

        // 2. Visiting operator dashboard with impersonation session
        $dashResponse = $this->actingAs($this->platformAdmin)
            ->withSession(['impersonate_tenant' => $this->acme->id])
            ->get(route('operator.dashboard'));

        $dashResponse->assertOk();
        $dashResponse->assertSee('Acme Wi-Fi');
        $dashResponse->assertSee('Exit Impersonation');

        // 3. Stop impersonating
        $exitResponse = $this->actingAs($this->platformAdmin)
            ->withSession(['impersonate_tenant' => $this->acme->id])
            ->get(route('platform.stop-impersonate'));

        $exitResponse->assertRedirect(route('platform.dashboard'));
        $exitResponse->assertSessionMissing('impersonate_tenant');
    }
}
