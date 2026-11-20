<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\RadiusRuntimeHealthCheck;
use App\Services\RouterConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyRadiusReadinessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $routerId) {}

    public function handle(
        RadiusRuntimeHealthCheck $runtime,
        RouterConnectionService $connection,
    ): void {
        $router = Router::withoutTenancy()
            ->with(['vpnPeer', 'onboarding'])
            ->find($this->routerId);
        if (! $router || ! $router->pending_radius_activation) {
            return;
        }

        $ready = $runtime->available()
            && ($connection->testOnboardingWireGuard($router)['ok'] ?? false)
            && $connection->hasOnboardingRadiusConfiguration($router);

        $router->forceFill([
            'radius_readiness_status' => $ready ? 'ready' : 'blocked',
            'radius_readiness_checked_at' => now(),
        ])->saveOrFail();

        if (! $ready) {
            Log::warning('Router RADIUS readiness check is blocked; its existing access backend remains active.', [
                'router_id' => $router->id,
            ]);
        }
    }
}
