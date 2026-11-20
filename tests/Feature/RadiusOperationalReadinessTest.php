<?php

namespace Tests\Feature;

use App\Services\RadiusReadiness;
use App\Services\RadiusRuntimeHealthCheck;
use App\Services\SystemHealthService;
use Tests\TestCase;

class RadiusOperationalReadinessTest extends TestCase
{
    public function test_runtime_and_worker_health_are_reported_without_gating_radius_policy_probe(): void
    {
        config([
            'radius.enabled' => true,
            'radius.production_enabled' => false,
        ]);
        $this->mock(SystemHealthService::class, function ($mock): void {
            $mock->shouldReceive('queueWorkerReady')->once()->andReturn(false);
        });
        $this->mock(RadiusRuntimeHealthCheck::class, function ($mock): void {
            $mock->shouldNotReceive('available');
        });

        $this->assertTrue(RadiusReadiness::available());
        $this->assertFalse(RadiusReadiness::operational());
    }

    public function test_disabled_radius_is_not_operational_and_does_not_probe_runtime(): void
    {
        config(['radius.enabled' => false]);
        $this->mock(SystemHealthService::class, function ($mock): void {
            $mock->shouldNotReceive('queueWorkerReady');
        });
        $this->mock(RadiusRuntimeHealthCheck::class, function ($mock): void {
            $mock->shouldNotReceive('available');
        });

        $this->assertFalse(RadiusReadiness::available());
        $this->assertFalse(RadiusReadiness::operational());
    }
}
