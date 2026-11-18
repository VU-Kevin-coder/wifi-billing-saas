<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use LogicException;

class SiteAgentAccessStrategy implements RouterAccessStrategy
{
    public function __construct(
        private readonly SiteAgentCommandService $commands,
        private readonly TenantContext $context,
    ) {}

    public function assertReady(Router $router, ?SiteAgent $siteAgent): void
    {
        if (config('site_agent.required') && (! $router->site_id || ! $siteAgent)) {
            throw new LogicException('This site is not connected to a paired site agent. Access was not activated.');
        }
    }

    public function initialSessionStatus(Router $router, ?SiteAgent $siteAgent): string
    {
        return $siteAgent ? 'provisioning' : 'active';
    }

    public function provision(Router $router, Session $session, Voucher $voucher, ?SiteAgent $siteAgent): void
    {
        if ($siteAgent) {
            $this->commands->provision($router, $session, $voucher);
        }
    }

    public function revoke(
        Tenant $tenant,
        Router $router,
        Session $session,
        Voucher $voucher,
        string $finalSessionStatus,
    ): bool {
        $this->context->runAs($tenant, fn () => $this->commands->expire(
            $router,
            $session,
            $voucher,
            $finalSessionStatus,
        ));

        return true;
    }
}
