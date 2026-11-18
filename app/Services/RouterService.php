<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Router;
use App\Services\Tenancy\TenantContext;
use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterService
{
    protected ?Tenant $tenant = null;

    public function __construct(?Tenant $tenant = null)
    {
        if ($tenant) {
            $this->tenant = $tenant;
        } elseif (app()->bound(TenantContext::class)) {
            $this->tenant = app(TenantContext::class)->getTenant();
        }
    }

    /**
     * Create a hotspot user on the router for a redeemed voucher.
     */
    public function createHotspotUser(string $voucherCode, string $packageName)
    {
        return $this->getHotspotManager()->createUser([
            'name' => $voucherCode,
            'password' => $voucherCode,
            'profile' => $packageName,
            'comment' => 'Created by Wi-Fi Billing SaaS',
        ]);
    }

    /**
     * Fetch active hotspot sessions/hosts from the router.
     */
    public function getActiveSessions(): array
    {
        return $this->getHotspotManager(requireEnrolledIdentity: false)->getActiveHosts();
    }

    /**
     * Test connection to the tenant's configured router.
     */
    public function testConnection(): bool
    {
        if (config('site_agent.required') || ! config('site_agent.allow_direct_router_connection')
            || ! $this->tenant || blank($this->tenant->mikrotik_host)) {
            return false;
        }

        try {
            $this->configureTenantRouter();
            $system = MikroTik::router($this->tenant->slug)->system();

            $resources = $system->getResources();

            return ! empty($resources);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve the HotspotManager with dynamic per-tenant configuration.
     */
    protected function getHotspotManager(bool $requireEnrolledIdentity = true)
    {
        if (config('site_agent.required') || ! config('site_agent.allow_direct_router_connection')) {
            throw new RuntimeException('Legacy direct router operations are disabled. Use the paired site agent or a router WireGuard peer.');
        }

        if (! $this->tenant) {
            throw new RuntimeException('A tenant router with verified API-SSL is required.');
        }

        if (blank($this->tenant->mikrotik_host)) {
            throw new RuntimeException('Tenant-level direct router credentials are not configured.');
        }

        $this->configureTenantRouter();
        if ($requireEnrolledIdentity) {
            $router = Router::withoutTenancy()
                ->where('tenant_id', $this->tenant->id)
                ->where('management_ip', $this->tenant->mikrotik_host)
                ->first();
            if (! $router) {
                throw new RuntimeException('Legacy direct router writes require an enrolled router record for this exact management host.');
            }

            app(RouterIdentityService::class)->assertMatchesRouter($router, $this->tenant->slug);
        }

        return MikroTik::router($this->tenant->slug)->hotspot();
    }

    private function configureTenantRouter(): void
    {
        $port = (int) ($this->tenant->mikrotik_port ?? config('services.mikrotik.api_port'));
        DynamicMikrotikRouterConfig::set($this->tenant->slug, [
            'username' => $this->tenant->mikrotik_username ?? 'admin',
            'password' => $this->tenant->mikrotik_password ?? '',
            ...RouterTransportConfig::forRouter($this->tenant->mikrotik_host, $port, $this->tenant->id),
            'timeout' => 10,
        ]);
    }
}
