<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use LogicException;

class RadiusAccessStrategy implements RouterAccessStrategy
{
    public function assertReady(Router $router): void
    {
        RadiusReadiness::requireAvailable();

        if (! $router->hasUsableRadiusConfiguration()) {
            throw new LogicException('This RADIUS router is not fully configured. Access was not activated.');
        }
    }

    public function initialSessionStatus(Router $router): string
    {
        return 'provisioning';
    }

    public function provision(Router $router, Session $session, Voucher $voucher): void {}

    public function revoke(
        Tenant $tenant,
        Router $router,
        Session $session,
        Voucher $voucher,
        string $finalSessionStatus,
    ): bool {
        return app(RouterCommandService::class)->requestKick(
            $session,
            "revocation:{$session->id}:{$finalSessionStatus}",
        );
    }
}
