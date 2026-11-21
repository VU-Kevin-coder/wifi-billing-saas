<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterOnboarding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RouterOnboardingService
{
    private const BOOTSTRAP_TTL_MINUTES = 30;

    private const MAX_BOOTSTRAP_FETCHES = 5;

    public function create(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        if (! $router->vpnPeer || ! $router->vpnPeer->enabled) {
            throw new LogicException('An enabled WireGuard peer is required to start router onboarding.');
        }

        $this->assertBootstrapUrlAvailable();
        $token = Str::random(64);
        $pollingToken = bin2hex(random_bytes(32));
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
            $pollingToken,
        );
        $router->forceFill([
            'polling_token_hash' => hash('sha256', $pollingToken),
        ])->saveOrFail();
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
            throw new LogicException('Router onboarding requires a publicly reachable HTTPS APP_URL.');
        }
    }

    public function bootstrapCommand(string $token): string
    {
        $this->assertBootstrapUrlAvailable();
        $url = rtrim((string) config('app.url'), '/')
            .route('api.provision.bootstrap', ['token' => $token], false);

        $url = $this->escapeRouterOs($url);

        return implode(PHP_EOL, [
            '/tool fetch url="'.$url.'" mode=https check-certificate=yes dst-path="wifi-billing-bootstrap.rsc"',
            '/import file-name="wifi-billing-bootstrap.rsc" verbose=yes',
            '/file remove "wifi-billing-bootstrap.rsc"',
        ]);
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

    public function markEnrolled(RouterOnboarding $onboarding): void
    {
        DB::transaction(function () use ($onboarding): void {
            $locked = RouterOnboarding::query()
                ->lockForUpdate()
                ->findOrFail($onboarding->id);
            if ($locked->status !== 'awaiting_bootstrap') {
                throw new LogicException('Router onboarding is not awaiting enrollment.');
            }

            $locked->forceFill([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'bootstrap_token_hash' => null,
                'bootstrap_script' => null,
                'bootstrap_command' => null,
            ])->saveOrFail();
            $onboarding->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markTunnelVerified(RouterOnboarding $onboarding): bool
    {
        return $this->advance($onboarding, 'tunnel_verified', 'tunnel_verified_at');
    }

    public function markApiVerified(RouterOnboarding $onboarding): bool
    {
        return $this->advance($onboarding, 'api_verified', 'api_verified_at');
    }

    public function markRadiusReady(RouterOnboarding $onboarding): bool
    {
        return $this->advance($onboarding, 'radius_ready', 'radius_ready_at');
    }

    public function markCompleted(RouterOnboarding $onboarding): void
    {
        $this->advance($onboarding, 'complete', 'completed_at');
    }

    private function advance(RouterOnboarding $onboarding, string $next, string $timestamp): bool
    {
        $states = [
            'awaiting_bootstrap',
            'enrolled',
            'tunnel_verified',
            'api_verified',
            'radius_ready',
            'complete',
        ];
        $targetIndex = array_search($next, $states, true);

        return DB::transaction(function () use ($onboarding, $next, $timestamp, $states, $targetIndex): bool {
            $locked = RouterOnboarding::query()
                ->lockForUpdate()
                ->findOrFail($onboarding->id);
            $currentIndex = array_search($locked->status, $states, true);
            if ($currentIndex === false || $targetIndex === false) {
                throw new LogicException('Router onboarding has an unsupported state.');
            }
            if ($currentIndex >= $targetIndex) {
                return false;
            }
            if ($currentIndex + 1 !== $targetIndex) {
                throw new LogicException('Router onboarding cannot skip a verification state.');
            }

            $locked->forceFill([
                'status' => $next,
                $timestamp => now(),
            ])->saveOrFail();
            $onboarding->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function escapeRouterOs(string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }
}
