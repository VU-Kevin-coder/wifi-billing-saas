<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
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
        $this->assertSame('package-'.$session->package_id, $command->payload['profile']);
        $this->assertSame('10M/10M', $command->payload['profile_settings']['rate-limit']);
        $this->assertSame(2, $command->payload['profile_settings']['shared-users']);
        $this->assertArrayNotHasKey('limit-uptime', $command->payload['profile_settings']);
        $this->assertArrayNotHasKey('limit-bytes-total', $command->payload['profile_settings']);
        $this->assertSame(5368709120, $command->payload['limit_bytes_total']);
        $this->assertArrayNotHasKey('rate_limit', $command->payload);
        $this->assertArrayNotHasKey('shared_users', $command->payload);
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

    public function test_bulk_profile_sync_waits_for_site_agent_success_before_marking_router_online(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $token = 'profile-sync-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);

        $response = $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all");

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile sync queued for 1 of 1 router(s); 0 could not be queued.');
        $command = SiteAgentCommand::query()->where('operation', 'sync_profile')->sole();
        $package = Package::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNull($command->session_id);
        $this->assertSame('pending', $command->status);
        $this->assertSame('package-'.$package->id, $command->payload['profiles'][0]['name']);
        $this->assertSame('10M/10M', $command->payload['profiles'][0]['rate-limit']);
        $this->assertArrayNotHasKey('limit-uptime', $command->payload['profiles'][0]);
        $this->assertArrayNotHasKey('limit-bytes-total', $command->payload['profiles'][0]);
        $this->assertSame('offline', $router->fresh()->status);
        $this->actingAs($operator)
            ->get("http://{$tenant->slug}.localhost/operator/settings/router")
            ->assertOk()
            ->assertSee('Latest profile sync per router')
            ->assertSee('Pending — awaiting site-agent result');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'sync_profile')
            ->assertJsonPath('commands.0.payload.profiles.0.rate-limit', '10M/10M');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/site-agent/commands/{$command->id}/complete", ['success' => true])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame('online', $router->fresh()->status);
        $this->assertNotNull($router->fresh()->last_seen_at);
    }

    public function test_failed_final_profile_sync_does_not_mark_router_online(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $agent->update(['token_hash' => hash('sha256', 'failed-profile-token')]);

        $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all")
            ->assertRedirect();
        $command = SiteAgentCommand::query()->where('operation', 'sync_profile')->sole();
        $this->withHeader('Authorization', 'Bearer failed-profile-token')
            ->getJson('/api/site-agent/commands')
            ->assertOk();
        $command->forceFill(['attempts' => 5])->save();

        $this->withHeader('Authorization', 'Bearer failed-profile-token')
            ->postJson("/api/site-agent/commands/{$command->id}/complete", [
                'success' => false,
                'message' => 'Router rejected profile settings.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('offline', $router->fresh()->status);
        $this->assertSame('failed', $command->fresh()->status);
    }

    public function test_bulk_profile_sync_reports_routers_without_a_paired_agent(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $unpairedRouter = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unpaired Router',
            'status' => 'offline',
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $response = $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all");

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile sync queued for 1 of 2 router(s); 1 could not be queued.');
        $response->assertSessionHas('router_sync_results', function (array $results): bool {
            return $results[0]['status'] === 'pending'
                && $results[1]['router'] === 'Unpaired Router'
                && $results[1]['status'] === 'failed';
        });
        $this->assertSame('offline', $router->fresh()->status);
        $this->assertSame('offline', $unpairedRouter->fresh()->status);
    }

    public function test_router_api_test_is_queued_and_agent_result_controls_router_status(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $token = 'router-api-test-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/routers/{$router->id}/test")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        $command = SiteAgentCommand::query()->where('operation', 'test_connection')->sole();
        $this->assertSame('offline', $router->fresh()->status);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'test_connection');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/site-agent/commands/{$command->id}/complete", ['success' => true])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame('online', $router->fresh()->status);
        $this->assertNotNull($router->fresh()->last_seen_at);
    }

    public function test_legacy_router_settings_test_is_queued_in_agent_mode_and_blocked_without_direct_opt_in(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent] = $this->createSiteSetup();
        $tenant->update(['mikrotik_host' => '198.51.100.10']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/settings/router/test")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);
        $this->assertDatabaseHas('site_agent_commands', ['operation' => 'test_connection']);

        config([
            'site_agent.required' => false,
            'site_agent.allow_direct_router_connection' => false,
        ]);
        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/settings/router/test")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->assertSame(1, SiteAgentCommand::query()->where('operation', 'test_connection')->count());
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
