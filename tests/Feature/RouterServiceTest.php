<?php

namespace Tests\Feature;

use App\Services\RouterService;
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
}
