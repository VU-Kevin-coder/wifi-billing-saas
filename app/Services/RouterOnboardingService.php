<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Session;
use App\Models\VpnPeer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

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
        $material = $this->buildBootstrapMaterial($router, $router->vpnPeer);
        $router->forceFill([
            'polling_token_hash' => hash('sha256', $material['polling_token']),
        ])->saveOrFail();
        $onboarding = RouterOnboarding::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', $material['token']),
            'bootstrap_script' => $material['script'],
            'bootstrap_command' => $material['command'],
            'status' => 'awaiting_bootstrap',
            'expires_at' => now()->addMinutes(self::BOOTSTRAP_TTL_MINUTES),
        ]);

        return [
            'onboarding' => $onboarding,
            'command' => $material['command'],
        ];
    }

    /**
     * Reissue onboarding for a router that has not enrolled yet, replacing its
     * one-time WireGuard peer and invalidating the previously issued bootstrap.
     *
     * @return array{onboarding: RouterOnboarding, command: string}
     */
    public function reissue(Router $router): array
    {
        $this->assertBootstrapUrlAvailable();

        $router = Router::withoutGlobalScopes()
            ->where('tenant_id', $router->tenant_id)
            ->findOrFail($router->getKey());
        $onboarding = RouterOnboarding::query()
            ->where('router_id', $router->getKey())
            ->first();

        if (! $onboarding || $router->enrolled || $onboarding->status !== 'awaiting_bootstrap') {
            throw new LogicException('Only a router that is still awaiting its first bootstrap can be reissued.');
        }

        if (Session::withoutGlobalScopes()
            ->where('router_id', $router->getKey())
            ->where('status', 'active')
            ->exists()) {
            throw new LogicException('Disconnect active sessions before reissuing router onboarding.');
        }

        DB::transaction(function () use ($router): void {
            $lockedRouter = Router::withoutGlobalScopes()
                ->where('tenant_id', $router->tenant_id)
                ->lockForUpdate()
                ->findOrFail($router->getKey());
            $lockedOnboarding = RouterOnboarding::query()
                ->where('router_id', $router->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRouter->enrolled || $lockedOnboarding->status !== 'awaiting_bootstrap') {
                throw new LogicException('Only a router that is still awaiting its first bootstrap can be reissued.');
            }

            if (Session::withoutGlobalScopes()
                ->where('router_id', $router->getKey())
                ->where('status', 'active')
                ->exists()) {
                throw new LogicException('Disconnect active sessions before reissuing router onboarding.');
            }

            $lockedOnboarding->forceFill([
                'bootstrap_token_hash' => null,
                'bootstrap_script' => null,
                'bootstrap_command' => null,
                'bootstrap_fetches' => 0,
                'status' => 'reissuing',
                'expires_at' => now(),
                'connected_at' => null,
                'enrolled_at' => null,
                'tunnel_verified_at' => null,
                'api_verified_at' => null,
                'radius_ready_at' => null,
                'completed_at' => null,
            ])->saveOrFail();
            $lockedRouter->forceFill([
                'polling_token_hash' => null,
                'status' => 'provisioning',
            ])->saveOrFail();
        });

        $wireGuard = app(WireGuardService::class);
        $newPeer = null;
        try {
            $oldPeer = VpnPeer::query()->where('router_id', $router->getKey())->first();
            if ($oldPeer) {
                $wireGuard->revokePeer($oldPeer);
            }

            $freshRouter = Router::withoutGlobalScopes()->findOrFail($router->getKey());
            $createdPeer = $wireGuard->createPeer($freshRouter, $freshRouter->name);
            $newPeer = $createdPeer;
            $material = $this->buildBootstrapMaterial($freshRouter, $createdPeer);

            DB::transaction(function () use ($freshRouter, $material): void {
                $lockedRouter = Router::withoutGlobalScopes()
                    ->where('tenant_id', $freshRouter->tenant_id)
                    ->lockForUpdate()
                    ->findOrFail($freshRouter->getKey());
                $lockedOnboarding = RouterOnboarding::query()
                    ->where('router_id', $freshRouter->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRouter->enrolled || $lockedOnboarding->status !== 'reissuing') {
                    throw new LogicException('Router onboarding changed while a replacement script was being generated.');
                }

                $lockedRouter->forceFill([
                    'polling_token_hash' => hash('sha256', $material['polling_token']),
                ])->saveOrFail();
                $lockedOnboarding->forceFill([
                    'bootstrap_token_hash' => hash('sha256', $material['token']),
                    'bootstrap_script' => $material['script'],
                    'bootstrap_command' => $material['command'],
                    'bootstrap_fetches' => 0,
                    'status' => 'awaiting_bootstrap',
                    'expires_at' => now()->addMinutes(self::BOOTSTRAP_TTL_MINUTES),
                ])->saveOrFail();
            });
        } catch (Throwable $exception) {
            $cleanupException = null;
            if ($newPeer) {
                try {
                    $wireGuard->revokePeer($newPeer);
                } catch (Throwable $caughtCleanupException) {
                    $cleanupException = $caughtCleanupException;
                }
            }

            try {
                RouterOnboarding::query()
                    ->where('router_id', $router->getKey())
                    ->where('status', 'reissuing')
                    ->update(['status' => 'awaiting_bootstrap']);
            } catch (Throwable $stateException) {
                Log::critical('Failed to restore onboarding state after a reissue error.', [
                    'router_id' => $router->getKey(),
                    'exception' => $exception::class,
                    'state_exception' => $stateException::class,
                    'cleanup_exception' => $cleanupException ? $cleanupException::class : null,
                ]);

                throw new RuntimeException(
                    'Onboarding reissue failed and its state could not be restored. Inspect the router and WireGuard peer before retrying.',
                    0,
                    $stateException,
                );
            }

            if ($cleanupException) {
                Log::critical('Failed to clean up a WireGuard peer after onboarding reissue failed.', [
                    'router_id' => $router->getKey(),
                    'exception' => $exception::class,
                    'cleanup_exception' => $cleanupException::class,
                ]);

                throw new RuntimeException(
                    'Onboarding reissue failed and its replacement WireGuard peer could not be removed. Inspect the router peer on the server before retrying.',
                    0,
                    $cleanupException,
                );
            }

            throw $exception;
        }

        return [
            'onboarding' => RouterOnboarding::query()
                ->where('router_id', $freshRouter->getKey())
                ->firstOrFail(),
            'command' => $material['command'],
        ];
    }

    public function assertBootstrapUrlAvailable(): void
    {
        $parts = parse_url((string) config('app.url'));
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new LogicException('Router onboarding requires a publicly reachable HTTPS APP_URL.');
        }
    }

    /**
     * @return array{token: string, polling_token: string, command: string, script: string}
     */
    private function buildBootstrapMaterial(Router $router, VpnPeer $peer): array
    {
        $token = Str::random(64);
        $pollingToken = bin2hex(random_bytes(32));
        $radiusSecret = app(RadiusRouterCredentialService::class)->preparePendingActivation(
            $router,
            'router-'.$router->id.'-tenant-'.$router->tenant_id,
        );

        return [
            'token' => $token,
            'polling_token' => $pollingToken,
            'command' => $this->bootstrapCommand($token),
            'script' => app(WireGuardService::class)->onboardingConfiguration(
                $peer,
                $router,
                $token,
                $radiusSecret,
                $pollingToken,
            ),
        ];
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
