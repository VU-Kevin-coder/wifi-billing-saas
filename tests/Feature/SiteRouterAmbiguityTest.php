<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SiteRouterAmbiguityTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_context_with_multiple_routers_fails_closed(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, , , $voucher] = $this->createSiteSetup();
        Router::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'Second Router']);

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), null, ['site_id' => $site->id]));
            $this->fail('Activation unexpectedly selected a router from an ambiguous site.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('multiple routers', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
        $this->assertDatabaseCount('site_agent_commands', 0);
    }

    public function test_legacy_site_name_with_multiple_routers_fails_closed(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, $router, , $voucher] = $this->createSiteSetup();
        $router->update(['site' => $site->name]);
        Router::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'site' => $site->name, 'name' => 'Second Router']);
        $voucher->update(['assigned_site' => $site->name]);

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/')));
            $this->fail('Activation unexpectedly selected a router from an ambiguous legacy site.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('multiple routers', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
        $this->assertDatabaseCount('site_agent_commands', 0);
    }

    public function test_explicit_router_still_works_at_a_site_with_multiple_routers(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, $router, , $voucher] = $this->createSiteSetup();
        Router::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'Second Router']);

        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), null, ['router_id' => $router->id, 'site_id' => $site->id]));

        $this->assertSame($router->id, $session->router_id);
        $this->assertSame('provisioning', $session->status);
        $this->assertSame($router->id, SiteAgentCommand::query()->sole()->router_id);
    }

    private function createSiteSetup(): array
    {
        $tenant = Tenant::create(['name' => 'Agent Test', 'slug' => 'agent-test', 'status' => 'active']);
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'North Site']);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Router',
            'status' => 'online',
        ]);
        $agent = SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Site Agent',
            'token_hash' => hash('sha256', 'not-used-token'),
        ]);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One hour',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 2,
            'data_limit' => '5GB',
            'radius_profile_name' => 'guest-hour',
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'AGENT-TEST',
            'quantity' => 1,
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'AGENT-001',
            'status' => 'unused',
        ]);

        return [$tenant, $site, $router, $agent, $voucher];
    }
}
