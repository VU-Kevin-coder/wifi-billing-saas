<?php

namespace Tests\Feature;

use App\Console\Commands\RunSiteAgent;
use App\Models\BlockedDevice;
use App\Models\Router;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Services\SiteAgentRouterService;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SiteAgentSecretSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_does_not_output_or_post_router_exception_secrets(): void
    {
        $secret = 'synthetic-password-secret-4827';
        Http::fake();
        $router = \Mockery::mock(SiteAgentRouterService::class);
        $router->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Router password='.$secret));
        $output = new BufferedOutput;
        $worker = new class extends RunSiteAgent {
            public function testCommand(SiteAgentRouterService $router, PendingRequest $http, BufferedOutput $output): void
            {
                $this->components = new Factory(new OutputStyle(new ArrayInput([]), $output));
                $this->processCommand($router, $http, 'https://example.test', ['id' => 7, 'router_id' => 4, 'operation' => 'test_connection']);
            }
        };
        $worker->testCommand($router, Http::acceptJson(), $output);
        $console = $output->fetch();
        $this->assertStringNotContainsString($secret, $console);
        $this->assertStringContainsString('Router command failed.', $console);
        Http::assertSent(function ($request) use ($secret) {
            $this->assertStringNotContainsString($secret, $request->body());
            $this->assertSame(['success' => false, 'message' => 'Router command failed.'], $request->data());
            return $request->url() === 'https://example.test/api/site-agent/commands/7/complete';
        });
    }

    public function test_server_discards_legacy_agent_error_for_command_and_device(): void
    {
        $tenant = Tenant::create(['kyc_status' => 'verified', 'name' => 'Safety', 'slug' => 'safety', 'status' => 'active']);
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'Test']);
        $router = Router::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'Test', 'status' => 'online']);
        $agent = SiteAgent::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'name' => 'Test', 'token_hash' => hash('sha256', 'synthetic-agent-token')]);
        $device = BlockedDevice::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'mac_address' => 'AA:BB:CC:DD:EE:FF', 'status' => 'pending']);
        $job = SiteAgentCommand::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'site_agent_id' => $agent->id, 'operation' => 'ban_mac', 'payload' => ['blocked_device_id' => $device->id], 'status' => 'claimed', 'attempts' => 5]);
        $secret = 'synthetic-password-secret-4827';
        $response = $this->withHeader('Authorization', 'Bearer synthetic-agent-token')->postJson('/api/site-agent/commands/'.$job->id.'/complete', ['success' => false, 'message' => 'Router password='.$secret]);
        $response->assertOk()->assertJsonPath('status', 'failed');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertSame('Router command failed.', $job->fresh()->failure_reason);
        $this->assertSame('Router command failed.', BlockedDevice::withoutGlobalScopes()->findOrFail($device->id)->last_error);
    }
}
