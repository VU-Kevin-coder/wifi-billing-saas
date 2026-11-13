<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\Tenant;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class VoucherActivationService
{
    public function activate(
        Tenant $tenant,
        Voucher $voucher,
        Request $request,
        ?string $customerPhone = null,
        array $sessionContext = [],
    ): Session {
        return DB::transaction(function () use ($tenant, $voucher, $request, $customerPhone, $sessionContext) {
            $voucher = Voucher::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->findOrFail($voucher->id);

            $session = Session::query()
                ->where('tenant_id', $tenant->id)
                ->where('voucher_id', $voucher->id)
                ->lockForUpdate()
                ->first();

            if ($session) {
                if (! in_array($session->status, ['active', 'provisioning'], true)
                    || $session->expires_at?->isPast()) {
                    throw new LogicException('This voucher session has already expired or disconnected.');
                }

                return $session;
            }

            if ($voucher->status !== 'unused') {
                throw new LogicException('This voucher has already been activated.');
            }

            $startedAt = now();
            $package = Package::where('tenant_id', $tenant->id)->find($voucher->package_id);
            if (! $package) {
                throw new LogicException('The voucher package is no longer available.');
            }

            $router = $this->resolveRouter($tenant, $voucher, $request, $sessionContext);
            $site = $this->resolveSite($tenant, $voucher, $router);
            $siteAgent = $router?->site_id
                ? SiteAgent::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('site_id', $router->site_id)
                    ->whereNull('revoked_at')
                    ->first()
                : null;

            if (config('site_agent.required') && (! $router || ! $siteAgent)) {
                throw new LogicException('This site is not connected to a paired site agent. Access was not activated.');
            }

            $phone = $customerPhone ?: $voucher->redeemed_by ?: 'Walk-in';
            $customer = Customer::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'phone' => $phone,
                ],
                [
                    'name' => $phone === 'Walk-in' ? 'Walk-in Guest' : 'Online Customer',
                    'status' => 'active',
                    'package_id' => $package->id,
                ]
            );

            $voucher->update([
                'status' => 'redeemed',
                'redeemed_at' => $startedAt,
                'redeemed_price' => $package->price,
                'redeemed_by' => $phone,
                'site_id' => $voucher->site_id ?? $site?->id,
            ]);

            $clientIp = $sessionContext['ip'] ?? $request->input('ip') ?? $request->ip();
            $clientMac = $sessionContext['mac']
                ?? $request->input('mac')
                ?? $request->header('X-Client-Mac');

            $session = Session::create([
                'tenant_id' => $tenant->id,
                'site_id' => $site?->id,
                'customer_id' => $customer->id,
                'voucher_id' => $voucher->id,
                'package_id' => $package->id,
                'router_id' => $router?->id,
                'status' => $siteAgent ? 'provisioning' : 'active',
                'started_at' => $startedAt,
                'expires_at' => $package->expiresAt($startedAt),
                'ip_address' => $clientIp,
                'mac_address' => $clientMac,
            ]);

            if ($siteAgent) {
                app(SiteAgentCommandService::class)->provision($router, $session, $voucher);
            }

            return $session;
        });
    }

    private function resolveRouter(Tenant $tenant, Voucher $voucher, Request $request, array $context): ?Router
    {
        $routerId = $context['router_id'] ?? $request->input('router_id');
        if ($routerId) {
            $router = Router::where('tenant_id', $tenant->id)->find($routerId);
            if ($router) {
                return $router;
            }
        }

        $routerIdentity = $context['router'] ?? $request->input('router');
        if ($routerIdentity) {
            $router = Router::where('tenant_id', $tenant->id)
                ->where(function ($query) use ($routerIdentity): void {
                    $query->where('name', $routerIdentity)
                        ->orWhere('serial_number', $routerIdentity);
                })
                ->first();
            if ($router) {
                return $router;
            }
        }

        $siteId = $context['site_id'] ?? $voucher->site_id;
        if ($siteId) {
            $routers = Router::where('tenant_id', $tenant->id)->where('site_id', $siteId)->limit(2)->get();
            if (config('site_agent.required') && $routers->count() > 1) {
                throw new LogicException('This site has multiple routers. Specify the router before activating access.');
            }
            if ($routers->isNotEmpty()) {
                return $routers->first();
            }
        }

        if ($voucher->assigned_site) {
            $routers = Router::where('tenant_id', $tenant->id)
                ->where('site', $voucher->assigned_site)
                ->limit(2)->get();
            if (config('site_agent.required') && $routers->count() > 1) {
                throw new LogicException('This site has multiple routers. Specify the router before activating access.');
            }
            if ($routers->isNotEmpty()) {
                return $routers->first();
            }
        }

        $fallbackRouters = Router::where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if (config('site_agent.required') && $fallbackRouters->count() > 1) {
            throw new LogicException('This voucher is not bound to a router. Access was not activated.');
        }

        return $fallbackRouters->first();
    }

    private function resolveSite(Tenant $tenant, Voucher $voucher, ?Router $router): ?Site
    {
        if ($router?->site_id) {
            $site = Site::where('tenant_id', $tenant->id)->find($router->site_id);
            if ($site) {
                return $site;
            }
        }

        if ($router?->site) {
            $site = Site::where('tenant_id', $tenant->id)->where('name', $router->site)->first();
            if ($site) {
                return $site;
            }
        }

        if ($voucher->site_id) {
            $site = Site::where('tenant_id', $tenant->id)->find($voucher->site_id);
            if ($site) {
                return $site;
            }
        }

        if ($voucher->assigned_site) {
            $site = Site::where('tenant_id', $tenant->id)
                ->where('name', $voucher->assigned_site)
                ->first();
            if ($site) {
                return $site;
            }
        }

        return Site::where('tenant_id', $tenant->id)->orderBy('id')->first();
    }
}
