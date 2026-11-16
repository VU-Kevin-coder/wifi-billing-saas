<?php

namespace App\Services;

use App\Models\RadiusAccountingSession;
use App\Models\RadiusDisconnectCommand;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgentCommand;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class RadiusRouterCredentialService
{
    /**
     * Opt one persisted router into RADIUS and return its generated secret once.
     * The model's encrypted cast protects storage and its hidden field prevents
     * ordinary model serialization from returning this credential.
     */
    public function optIn(Router $router, string $nasIdentifier): string
    {
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

            if ($lockedRouter->access_backend !== 'radius'
                && (Session::withoutTenancy()->where('tenant_id', $lockedRouter->tenant_id)
                    ->where('router_id', $lockedRouter->id)->where(function ($query): void {
                        $query->whereIn('status', ['active', 'provisioning', 'disconnecting'])
                            ->orWhere('pending_revocation', true)->orWhere('pending_device_ban', true);
                    })->exists()
                    || SiteAgentCommand::query()->where('tenant_id', $lockedRouter->tenant_id)->where('router_id', $lockedRouter->id)->whereIn('status', ['pending', 'claimed'])->exists()
                    || RadiusAccountingSession::query()->where('tenant_id', $lockedRouter->tenant_id)->where('router_id', $lockedRouter->id)->where('state', 'open')->exists()
                    || RadiusDisconnectCommand::query()->where('tenant_id', $lockedRouter->tenant_id)->where('router_id', $lockedRouter->id)->whereIn('state', ['pending', 'retry_wait', 'leased', 'delivery_acknowledged'])->exists())) {
                throw new LogicException('The router has outstanding sessions or commands; its backend cannot be switched.');
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
}
