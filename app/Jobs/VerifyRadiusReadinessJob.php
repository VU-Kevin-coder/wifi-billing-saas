<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\RadiusReadiness;
use App\Services\RouterConnectionService;
use App\Services\RouterOnboardingService;
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
        RouterConnectionService $connection,
        RouterOnboardingService $onboardingService,
    ): void {
        $router = Router::withoutTenancy()
            ->with(['vpnPeer', 'onboarding'])
            ->find($this->routerId);
        if (! $router
            || ! $router->onboarding
            || ! in_array($router->onboarding->status, ['api_verified', 'radius_ready'], true)
            || ($router->access_backend !== 'radius' && ! $router->pending_radius_activation)) {
            return;
        }

        $ready = RadiusReadiness::operational()
            && ($connection->testOnboardingWireGuard($router)['ok'] ?? false)
            && $connection->hasOnboardingRadiusConfiguration($router);

        $router->forceFill([
            'radius_readiness_status' => $ready ? 'ready' : 'blocked',
            'radius_readiness_checked_at' => now(),
        ])->saveOrFail();

        if ($ready) {
            $onboardingService->markRadiusReady($router->onboarding);
        } else {
            Log::warning('Router RADIUS readiness check is blocked; its existing access backend remains active.', [
                'router_id' => $router->id,
            ]);
        }
    }
}
