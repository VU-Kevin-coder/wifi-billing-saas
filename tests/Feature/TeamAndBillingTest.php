<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected User $admin;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_support', 'operator_network'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $this->admin = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->admin->assignRole('operator_admin');
        $this->finance = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->finance->assignRole('operator_finance');

        URL::defaults(['tenant' => $this->acme->slug]);
    }

    public function test_team_page_lists_only_users_from_the_active_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Wi-Fi', 'slug' => 'other', 'status' => 'active']);
        $otherAdmin = User::factory()->create([
            'name' => 'Other Workspace User',
            'tenant_id' => $otherTenant->id,
        ]);
        $otherAdmin->assignRole('operator_admin');

        $response = $this->actingAs($this->admin)->get(route('operator.team.index'));

        $response->assertOk()
            ->assertSee($this->admin->name)
            ->assertSee($this->finance->name)
            ->assertSee('Roles & permissions')
            ->assertDontSee('Other Workspace User');
    }

    public function test_team_page_is_restricted_to_tenant_administrators(): void
    {
        $this->actingAs($this->finance)
            ->get(route('operator.team.index'))
            ->assertForbidden();
    }

    public function test_finance_user_can_view_billing_page_but_cannot_access_team_page(): void
    {
        $response = $this->actingAs($this->finance)->get(route('operator.billing.index'));

        $response->assertOk()
            ->assertSee('Subscription billing is not connected yet.')
            ->assertSee('Continue to checkout (unavailable)')
            ->assertSee('No payment will be submitted or collected.');
    }

    public function test_support_user_cannot_view_billing_page(): void
    {
        $support = User::factory()->create(['tenant_id' => $this->acme->id]);
        $support->assignRole('operator_support');

        $this->actingAs($support)
            ->get(route('operator.billing.index'))
            ->assertForbidden();
    }

    public function test_new_user_form_can_prefill_a_tenant_role(): void
    {
        $this->actingAs($this->admin)
            ->get(route('operator.staff.create', ['role' => 'operator_finance']))
            ->assertOk()
            ->assertSee('value="operator_finance" selected', false);
    }
}
