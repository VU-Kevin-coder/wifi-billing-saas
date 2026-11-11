<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
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

class SiteAgentControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_waits_for_site_agent_to_provision_router_access(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, $router, $agent, $voucher] = $this->createSiteSetup();

        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));

        $this->assertSame('provisioning', $session->status);
        $command = SiteAgentCommand::query()->sole();
        $this->assertSame('provision_user', $command->operation);
        $this->assertSame($agent->id, $command->site_agent_id);
        $this->assertSame($router->id, $command->router_id);
        $this->assertSame($voucher->code, $command->payload['username']);
        $this->assertMatchesRegularExpression('/^00:59:[0-5][0-9]$/', $command->payload['limit_uptime']);
        $this->assertArrayNotHasKey('api_password', $command->payload);
    }

    public function test_paired_agent_can_poll_and_acknowledge_provision_and_expiry(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent, $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $token = 'one-time-site-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'provision_user')
            ->assertJsonPath('commands.0.payload.username', $voucher->code);
        $command = SiteAgentCommand::query()->where('operation', 'provision_user')->firstOrFail();
        $this->assertSame('claimed', $command->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson("/api/site-agent/commands/{$command->id}/complete", [
            'success' => true,
        ])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame('active', Session::withoutTenancy()->findOrFail($session->id)->status);

        $session->update(['expires_at' => now()->subSecond()]);
        $this->artisan('sessions:expire')->assertSuccessful();
        $expiry = SiteAgentCommand::query()->where('operation', 'expire_user')->firstOrFail();
        $this->assertSame('pending', $expiry->status);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'expire_user');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson("/api/site-agent/commands/{$expiry->id}/complete", [
            'success' => true,
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame('expired', Session::withoutTenancy()->findOrFail($session->id)->status);
    }

    public function test_production_activation_fails_closed_when_router_is_ambiguous(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , , $voucher] = $this->createSiteSetup();
        $otherSite = Site::create(['tenant_id' => $tenant->id, 'name' => 'South Site']);
        Router::create([
            'tenant_id' => $tenant->id,
            'site_id' => $otherSite->id,
            'name' => 'South Router',
            'status' => 'online',
        ]);

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
            $this->fail('Activation unexpectedly chose a router without router context.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not bound to a router', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_production_activation_fails_closed_without_a_paired_site_agent(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent, $voucher] = $this->createSiteSetup();
        $agent->delete();

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
            $this->fail('Activation unexpectedly succeeded without a paired agent.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('paired site agent', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_site_agent_api_rejects_missing_or_invalid_tokens(): void
    {
        [, , , $agent] = $this->createSiteSetup();
        $this->getJson('/api/site-agent/commands')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/site-agent/commands')->assertUnauthorized();

        $agent->update(['revoked_at' => now()]);
        $this->withHeader('Authorization', 'Bearer unused-token')->getJson('/api/site-agent/commands')->assertUnauthorized();
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
