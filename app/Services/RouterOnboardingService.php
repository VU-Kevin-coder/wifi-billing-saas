<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Services\RadiusRouterCredentialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RouterOnboardingService
{
    private const BOOTSTRAP_TTL_MINUTES = 30;

    private const MAX_BOOTSTRAP_FETCHES = 5;

    public function create(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        if (! $router->vpnPeer || ! $router->vpnPeer->enabled) {
            throw new \LogicException('An enabled WireGuard peer is required to start router onboarding.');
        }

        $this->assertBootstrapUrlAvailable();
        $token = Str::random(64);
        $command = $this->bootstrapCommand($token);
        $radiusSecret = app(RadiusRouterCredentialService::class)->preparePendingActivation(
            $router,
            'router-'.$router->id.'-tenant-'.$router->tenant_id,
        );
        $script = app(WireGuardService::class)->onboardingConfiguration(
            $router->vpnPeer,
            $router,
            $token,
            $radiusSecret,
        );
        $onboarding = RouterOnboarding::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', $token),
            'bootstrap_script' => $script,
            'bootstrap_command' => $command,
            'status' => 'awaiting_bootstrap',
            'expires_at' => now()->addMinutes(self::BOOTSTRAP_TTL_MINUTES),
        ]);

        return [
            'onboarding' => $onboarding,
            'command' => $command,
        ];
    }

    public function assertBootstrapUrlAvailable(): void
    {
        $parts = parse_url((string) config('app.url'));
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new \LogicException('Router onboarding requires a publicly reachable HTTPS APP_URL.');
        }
    }

    public function bootstrapCommand(string $token): string
    {
        $this->assertBootstrapUrlAvailable();
        $url = rtrim((string) config('app.url'), '/')
            .route('api.provision.bootstrap', ['token' => $token], false);

        $url = $this->escapeRouterOs($url);

        return '/tool fetch url="'.$url.'" mode=https check-certificate=yes dst-path="wifi-billing-bootstrap.rsc"; /import file-name="wifi-billing-bootstrap.rsc"; /file remove "wifi-billing-bootstrap.rsc"';
    }

    public function fetchBootstrapScript(string $token): ?string
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use ($tokenHash): ?string {
            $onboarding = RouterOnboarding::query()
                ->where('bootstrap_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $onboarding
                || $onboarding->status !== 'awaiting_bootstrap'
                || $onboarding->expires_at->isPast()
                || $onboarding->bootstrap_fetches >= self::MAX_BOOTSTRAP_FETCHES) {
                return null;
            }

            $onboarding->increment('bootstrap_fetches');

            return $onboarding->bootstrap_script;
        });
    }

    public function markConnected(RouterOnboarding $onboarding): void
    {
        if ($onboarding->status !== 'awaiting_bootstrap') {
            return;
        }

        $onboarding->forceFill([
            'status' => 'connected',
            'connected_at' => now(),
            'bootstrap_token_hash' => null,
            'bootstrap_script' => null,
            'bootstrap_command' => null,
        ])->saveOrFail();
    }

    public function markCompleted(RouterOnboarding $onboarding): void
    {
        if ($onboarding->status !== 'connected') {
            throw new \LogicException('Router onboarding must be connected before service setup can complete.');
        }

        $onboarding->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'bootstrap_script' => null,
            'bootstrap_command' => null,
        ])->saveOrFail();
    }

    private function escapeRouterOs(string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }
}
