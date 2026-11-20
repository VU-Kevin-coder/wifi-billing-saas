<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;

interface RouterAccessStrategy
{
    public function assertReady(Router $router): void;

    public function initialSessionStatus(Router $router): string;

    public function provision(Router $router, Session $session, Voucher $voucher): void;

    public function revoke(
        Tenant $tenant,
        Router $router,
        Session $session,
        Voucher $voucher,
        string $finalSessionStatus,
    ): bool;
}
