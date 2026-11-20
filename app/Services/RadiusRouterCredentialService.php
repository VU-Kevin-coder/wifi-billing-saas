<?php

namespace App\Services;

use App\Models\Router;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class RadiusRouterCredentialService
{
    public const UNAVAILABLE_MESSAGE = 'RADIUS cannot be enabled while the policy service is unavailable. No router settings or credentials were changed.';

    /**
     * Opt one persisted router into RADIUS and return its generated secret once.
     * The model's encrypted cast protects storage and its hidden field prevents
     * ordinary model serialization from returning this credential.
     */
    public function optIn(Router $router, string $nasIdentifier): string
    {
        // Enforce this at the service boundary too, not just on the operator UI.
        if (! RadiusReadiness::available()) {
            throw new RadiusUnavailableException(self::UNAVAILABLE_MESSAGE);
        }

        $nasIdentifier = trim($nasIdentifier);

        if (! $router->exists || ! $router->getKey()) {
            throw new LogicException('A persisted router is required before RADIUS can be configured.');
        }

        if ($nasIdentifier === '' || strlen($nasIdentifier) > 255 || preg_match('/[\x00-\x20\x7f]/', $nasIdentifier)) {
            throw new InvalidArgumentException('NAS identity must be a non-empty, bounded identifier without control characters.');
        }

        return DB::transaction(function () use ($router, $nasIdentifier): string {
            $context = app(TenantContext::class);
            if ($context->hasTenant() && (int) $context->getTenantId() !== (int) $router->tenant_id) {
                throw new LogicException('A router credential cannot be provisioned outside its active tenant.');
            }

            // Keep the lock tenant-bound even when this internal service is
            // called outside an HTTP request with no active TenantContext.
            $lockedRouter = Router::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->lockForUpdate()
                ->findOrFail($router->getKey());

            if ($lockedRouter->getRawOriginal('radius_shared_secret') !== null) {
                throw new LogicException('A RADIUS credential already exists and cannot be disclosed again.');
            }

            $secret = bin2hex(random_bytes(32));

            $lockedRouter->forceFill([
                'access_backend' => 'radius',
                'radius_nas_identifier' => $nasIdentifier,
                'radius_shared_secret' => $secret,
            ]);

            // Eloquent's saveOrFail() still returns false when a model event
            // cancels the save. Do not disclose a credential unless it was
            // actually persisted.
            if (! $lockedRouter->save()) {
                throw new RuntimeException('The RADIUS credential could not be persisted.');
            }

            $router->setRawAttributes($lockedRouter->getAttributes(), true);

            return $secret;
        });
    }

    /**
     * Prepare the router for RADIUS without changing its active access backend.
     * The backend is switched only after FreeRADIUS reports a real Access-Accept.
     */
    public function preparePendingActivation(Router $router, string $nasIdentifier): string
    {
        if (! RadiusReadiness::available()) {
            throw new RadiusUnavailableException(self::UNAVAILABLE_MESSAGE);
        }

        $nasIdentifier = trim($nasIdentifier);
        if (! $router->exists || ! $router->getKey()) {
            throw new LogicException('A persisted router is required before RADIUS can be configured.');
        }
        if ($nasIdentifier === '' || strlen($nasIdentifier) > 255 || preg_match('/[\x00-\x20\x7f]/', $nasIdentifier)) {
            throw new InvalidArgumentException('NAS identity must be a non-empty, bounded identifier without control characters.');
        }

        return DB::transaction(function () use ($router, $nasIdentifier): string {
            $context = app(TenantContext::class);
            if ($context->hasTenant() && (int) $context->getTenantId() !== (int) $router->tenant_id) {
                throw new LogicException('A router credential cannot be provisioned outside its active tenant.');
            }

            $lockedRouter = Router::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->lockForUpdate()
                ->findOrFail($router->getKey());
            $secret = $lockedRouter->radius_shared_secret;
            if (! is_string($secret) || strlen($secret) < 64) {
                $secret = bin2hex(random_bytes(32));
            }

            $lockedRouter->forceFill([
                'radius_nas_identifier' => $nasIdentifier,
                'radius_shared_secret' => $secret,
                'pending_radius_activation' => $lockedRouter->access_backend !== 'radius',
                'radius_access_reject_streak' => 0,
                'radius_activation_paused_at' => null,
            ]);
            if (! $lockedRouter->save()) {
                throw new RuntimeException('The pending RADIUS credential could not be persisted.');
            }

            $router->setRawAttributes($lockedRouter->getAttributes(), true);

            return $secret;
        });
    }
}
