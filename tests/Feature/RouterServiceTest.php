<?php

namespace Tests\Feature;

use App\Services\HotspotProfileSynchronizer;
use App\Services\RouterService;
use App\Services\SiteAgentRouterService;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Testing\MikrotikFake;

class RouterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site_agent.required' => false,
            'site_agent.allow_direct_router_connection' => true,
        ]);
    }

    public function test_it_creates_a_hotspot_user_on_the_router()
    {
        // 1. Set up the fake to expect a call to '/ip/hotspot/user/add'
        $fake = MikrotikFake::fake([
            '/ip/hotspot/user/add' => [], // Return an empty success response
        ]);

        // 2. Call our service
        $service = new RouterService;
        $service->createHotspotUser('KL-TEST-1234', 'Daily-Express');

        // 3. Assert that the fake router was actually queried with the right command
        $fake->assertQueried('/ip/hotspot/user/add');
    }

    public function test_it_fetches_active_sessions()
    {
        // 1. Set up the fake to return some dummy session data
        $fake = MikrotikFake::fake([
            '/ip/hotspot/active/print' => [
                ['name' => 'user1', 'address' => '10.0.0.1'],
                ['name' => 'user2', 'address' => '10.0.0.2'],
            ],
        ]);

        // 2. Call our service
        $service = new RouterService;
        $sessions = $service->getActiveSessions();

        // 3. Assert the data came through
        $this->assertCount(2, $sessions);
    }

    public function test_site_agent_telemetry_collects_router_resources_and_hotspot_users(): void
    {
        config([
            'site_agent.routers.42' => [
                'host' => '192.0.2.42',
                'username' => 'agent',
                'password' => 'local-secret',
                'port' => 8729,
            ],
        ]);
        MikrotikFake::fake([
            '/system/resource/print' => [
                ['uptime' => '2d4h', 'cpu-load' => 12, 'free-memory' => 50, 'total-memory' => 100],
            ],
            '/ip/hotspot/active/print' => [
                ['user' => 'guest-1'],
                ['user' => 'guest-2'],
            ],
        ]);

        $telemetry = (new SiteAgentRouterService(new HotspotProfileSynchronizer))
            ->execute(42, 'telemetry', []);

        $this->assertSame([
            'cpu_usage' => 12,
            'memory_usage' => 50,
            'active_users' => 2,
            'uptime' => '2d4h',
        ], $telemetry);
    }
}
