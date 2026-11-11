<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $this->acmeUser = User::factory()->create(['tenant_id' => $this->acme->id]);
        $this->acmeUser->assignRole('operator_admin');
    }

    public function test_customer_search_and_status_filters_are_applied(): void
    {
        $this->createCustomer($this->acme, [
            'name' => 'Alice Example',
            'phone' => '0771000000',
            'status' => 'inactive',
        ]);
        $this->createCustomer($this->acme, [
            'name' => 'Alice Active',
            'phone' => '0772000000',
            'status' => 'active',
        ]);
        $this->createCustomer($this->acme, [
            'name' => 'Bob Example',
            'phone' => '0773000000',
            'status' => 'inactive',
        ]);
        $this->createCustomer($this->beta, [
            'name' => 'Alice Other Tenant',
            'phone' => '0774000000',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost:8000/operator/customers?q=alice&status=inactive');

        $response->assertOk()
            ->assertSee('Alice Example')
            ->assertDontSee('Alice Active')
            ->assertDontSee('Bob Example')
            ->assertDontSee('Alice Other Tenant');
    }

    public function test_customer_csv_export_respects_filters_tenant_scope_and_formula_safety(): void
    {
        $this->createCustomer($this->acme, [
            'name' => '=2+2',
            'phone' => '0771000000',
            'status' => 'inactive',
        ]);
        $this->createCustomer($this->acme, [
            'name' => 'Formula Active',
            'phone' => '0772000000',
            'status' => 'active',
        ]);
        $this->createCustomer($this->beta, [
            'name' => '=2+2',
            'phone' => '0773000000',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost:8000/operator/customers/export.csv?q=2%2B2&status=inactive');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringNotContainsString('Formula Active', $csv);
        $this->assertStringNotContainsString('0773000000', $csv);
    }

    /**
     * @param  array{name: string, phone: string, status: string}  $attributes
     */
    private function createCustomer(Tenant $tenant, array $attributes): Customer
    {
        return app(TenantContext::class)->runAs($tenant, fn () => Customer::create($attributes));
    }
}
