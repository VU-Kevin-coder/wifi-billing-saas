<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PulseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pulse_dashboard_is_limited_to_platform_admins(): void
    {
        Role::findOrCreate('platform_admin', 'web');
        Role::findOrCreate('operator_admin', 'web');

        $this->get('/pulse')->assertRedirect('/login');

        $operator = User::factory()->create();
        $operator->assignRole('operator_admin');
        $this->actingAs($operator)->get('/pulse')->assertForbidden();

        $platformAdmin = User::factory()->create(['tenant_id' => null]);
        $platformAdmin->assignRole('platform_admin');
        $this->actingAs($platformAdmin)->get('/pulse')->assertOk();
    }

    public function test_the_dashboard_registers_the_mikrotik_health_recorder_and_card(): void
    {
        $recorder = \ZillEAli\MikrotikLaravel\Pulse\Recorders\RouterHealthRecorder::class;
        $config = file_get_contents(config_path('pulse.php'));
        $dashboard = file_get_contents(resource_path('views/vendor/pulse/dashboard.blade.php'));
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));

        $this->assertArrayHasKey($recorder, config('pulse.recorders'));
        $this->assertStringContainsString('pulse.mikrotik-router-health', $dashboard);
        $this->assertStringContainsString("Schedule::command('pulse:check --once')", $consoleRoutes);
        $this->assertStringContainsString("'role:platform_admin'", $config);
    }
}
