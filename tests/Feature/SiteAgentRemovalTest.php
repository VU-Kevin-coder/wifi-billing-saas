<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteAgentRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_runtime_routes_are_removed_while_location_and_archive_tables_remain(): void
    {
        $this->getJson('/api/site-agent/commands')->assertNotFound();
        $this->postJson('/api/site-agent/commands/1/complete')->assertNotFound();
        $this->get('/operator/settings/router')->assertNotFound();

        $commands = Artisan::all();
        $this->assertArrayNotHasKey('site-agent:work', $commands);
        $this->assertArrayNotHasKey('site-agent:issue-token', $commands);
        $this->assertTrue(Schema::hasTable('sites'));
        $this->assertTrue(Schema::hasTable('site_agents'));
        $this->assertTrue(Schema::hasTable('site_agent_commands'));
    }
}
