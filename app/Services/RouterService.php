<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
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
        return $this->getHotspotManager()->getActiveHosts();
    }

    /**
     * Test connection to the tenant's configured router.
     */
    public function testConnection(): bool
    {
        try {
            $system = $this->tenant && $this->tenant->mikrotik_host
                ? MikroTik::router($this->tenant->slug)->system()
                : MikroTik::system();

            $resources = $system->getResources();

            return ! empty($resources);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve the HotspotManager with dynamic per-tenant configuration.
     */
    protected function getHotspotManager()
    {
        if ($this->tenant && $this->tenant->mikrotik_host) {
            DynamicMikrotikRouterConfig::set($this->tenant->slug, [
                'host' => $this->tenant->mikrotik_host,
                'username' => $this->tenant->mikrotik_username ?? 'admin',
                'password' => $this->tenant->mikrotik_password ?? '',
                'port' => (int) ($this->tenant->mikrotik_port ?? 8728),
                'timeout' => 10,
            ]);

            return MikroTik::router($this->tenant->slug)->hotspot();
        }

        return MikroTik::hotspot();
    }
}
