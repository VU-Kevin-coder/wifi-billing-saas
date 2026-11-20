<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantPathRoutingTest extends TestCase
{
    use RefreshDatabase;

    private static bool $hadUrlMode = false;

    private static ?string $previousUrlMode = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $previousUrlMode = getenv('TENANT_URL_MODE');
        self::$hadUrlMode = $previousUrlMode !== false;
        self::$previousUrlMode = $previousUrlMode === false ? null : $previousUrlMode;

        putenv('TENANT_URL_MODE=path');
        $_ENV['TENANT_URL_MODE'] = 'path';
        $_SERVER['TENANT_URL_MODE'] = 'path';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$hadUrlMode) {
            putenv('TENANT_URL_MODE='.self::$previousUrlMode);
            $_ENV['TENANT_URL_MODE'] = self::$previousUrlMode;
            $_SERVER['TENANT_URL_MODE'] = self::$previousUrlMode;
        } else {
            putenv('TENANT_URL_MODE');
            unset($_ENV['TENANT_URL_MODE'], $_SERVER['TENANT_URL_MODE']);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    public function test_tenant_urls_use_the_shared_host_and_path_prefix(): void
    {
        config(['app.url' => 'https://billing.example.test']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);

        $this->assertSame('billing.example.test', $tenant->host());
        $this->assertSame('https://billing.example.test/t/', Tenant::workspaceAddressPrefix());
        $this->assertSame('https://billing.example.test/t/acme/operator/dashboard', $tenant->url());
        $this->assertSame('https://billing.example.test/t/acme/portal', $tenant->url('portal'));
        $this->assertSame('https://billing.example.test/t/acme/portal', $tenant->settings['domain']);
    }

    public function test_registration_page_displays_the_shared_domain_path_prefix(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Workspace Slug')
            ->assertSee('http://localhost:8000/t/');
    }

    public function test_sso_route_is_generated_with_the_tenant_path_prefix(): void
    {
        $url = route('operator.sso.verify', [
            'tenant' => 'acme',
            'user' => 1,
            'token' => 'single-use-token',
        ]);

        $this->assertSame('/t/acme/sso/verify', parse_url($url, PHP_URL_PATH));
    }

    public function test_operator_can_access_their_workspace_by_path(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->get('/t/acme/operator/dashboard')
            ->assertOk();
    }

    public function test_operator_cannot_access_another_tenant_workspace_by_path(): void
    {
        Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $beta = Tenant::create(['name' => 'Beta Networks', 'slug' => 'beta']);
        $operator = User::factory()->create(['tenant_id' => $beta->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->get('/t/acme/operator/dashboard')
            ->assertForbidden();
    }

    public function test_operator_login_redirects_to_the_tenant_path_on_the_same_host(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->post('/login', [
            'email' => $operator->email,
            'password' => 'password',
        ])->assertRedirect('http://localhost:8000/t/acme/operator/dashboard');
    }
}
