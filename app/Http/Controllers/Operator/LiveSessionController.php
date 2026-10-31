<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Session;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class LiveSessionController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $tenant   = $context->getTenant();
        $tenantId = $context->getTenantId();

        // Auto-seed demo sessions if the table is empty for this tenant
        if ($tenantId && Session::where('tenant_id', $tenantId)->where('status', 'active')->count() === 0) {
            $this->seedDemoSessions($tenantId);
        }

        $query = Session::with(['customer', 'package', 'router'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now());

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                  ->orWhere('mac_address', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        // Router filter
        if ($routerId = $request->input('router_id')) {
            $query->where('router_id', $routerId);
        }

        // Site filter (through router)
        if ($site = $request->input('site')) {
            $query->whereHas('router', fn($q) => $q->where('site', $site));
        }

        $activeSessions = $query->orderBy('started_at', 'desc')->paginate(20)->withQueryString();

        // KPI aggregates across ALL active sessions for this tenant (not just current page)
        $allActive = Session::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now());

        $connectedNow        = $allActive->count();
        $downloadThroughput  = (clone $allActive)->sum('rate_out');  // bps
        $uploadThroughput    = (clone $allActive)->sum('rate_in');   // bps
        $dataUsedToday       = (clone $allActive)->sum('bytes_in') + (clone $allActive)->sum('bytes_out');

        // Counts by type for tab badges
        $hotspotCount = (clone $allActive)->whereHas('voucher')->count();
        $pppoeCount   = $connectedNow - $hotspotCount;

        $routers = Router::where('tenant_id', $tenantId)->select('id', 'name', 'site')->orderBy('name')->get();
        $sites   = $routers->pluck('site')->unique()->filter()->values();

        return view('operator.sessions.index', compact(
            'tenant',
            'activeSessions',
            'connectedNow',
            'downloadThroughput',
            'uploadThroughput',
            'dataUsedToday',
            'hotspotCount',
            'pppoeCount',
            'routers',
            'sites'
        ));
    }

    /**
     * Seed realistic demo sessions for a fresh tenant.
     */
    protected function seedDemoSessions(int $tenantId): void
    {
        $routers = Router::where('tenant_id', $tenantId)->where('status', 'online')->get();
        if ($routers->isEmpty()) {
            return;
        }

        $customers = \App\Models\Customer::where('tenant_id', $tenantId)->get();
        $packages  = \App\Models\Package::where('tenant_id', $tenantId)->get();

        $demos = [
            ['name' => 'Amina Namusoke',   'ip' => '10.50.1.184', 'mac' => '4C:BB:58:A1:22:01', 'site_idx' => 0, 'hours' => 2,  'mins' => 14, 'bytes_in' => 335544320,  'bytes_out' => 2576980378,  'rate_in' => 2202009,  'rate_out' => 13109300],
            ['name' => 'Brian Kato',       'ip' => '10.50.2.321', 'mac' => '3C:F8:62:B2:33:02', 'site_idx' => 1, 'hours' => 1,  'mins' => 8,  'bytes_in' => 98566144,   'bytes_out' => 901775360,   'rate_in' => 943718,   'rate_out' => 5033164],
            ['name' => 'Daniel Ssembatya', 'ip' => '10.50.4.142', 'mac' => '78:E4:00:C3:44:03', 'site_idx' => 2, 'hours' => 1,  'mins' => 30, 'bytes_in' => 39845888,   'bytes_out' => 432013312,   'rate_in' => 419430,   'rate_out' => 2724003],
            ['name' => 'Joseph Okello',    'ip' => '10.50.5.193', 'mac' => 'DC:A6:32:D4:55:04', 'site_idx' => 3, 'hours' => 3,  'mins' => 26, 'bytes_in' => 220200960,   'bytes_out' => 1932735283,  'rate_in' => 1258291,  'rate_out' => 6396656],
            ['name' => 'Ruth Nakiyingi',   'ip' => '10.50.1.237', 'mac' => 'B8:27:EB:E5:66:05', 'site_idx' => 0, 'hours' => 5,  'mins' => 12, 'bytes_in' => 566231040,   'bytes_out' => 4831838208,  'rate_in' => 3565158,  'rate_out' => 10737418],
            ['name' => 'Isaac Walusimbi',  'ip' => '10.50.6.106', 'mac' => 'E4:5F:01:F6:77:06', 'site_idx' => 1, 'hours' => 0,  'mins' => 42, 'bytes_in' => 18874368,   'bytes_out' => 247463936,   'rate_in' => 629145,   'rate_out' => 3355443],
            ['name' => 'Florence Atim',    'ip' => '10.50.3.318', 'mac' => '00:1A:2B:G7:88:07', 'site_idx' => 2, 'hours' => 7,  'mins' => 3,  'bytes_in' => 1181116006,  'bytes_out' => 8791285248,  'rate_in' => 6502374,  'rate_out' => 25971520],
        ];

        foreach ($demos as $i => $d) {
            $router   = $routers->get($d['site_idx'] % $routers->count()) ?? $routers->first();
            $customer = $customers->get($i % max($customers->count(), 1));
            $package  = $packages->first();

            Session::create([
                'tenant_id'   => $tenantId,
                'customer_id' => $customer?->id,
                'package_id'  => $package?->id,
                'router_id'   => $router->id,
                'status'      => 'active',
                'started_at'  => now()->subHours($d['hours'])->subMinutes($d['mins']),
                'expires_at'  => now()->addHours(6),
                'ip_address'  => $d['ip'],
                'mac_address' => $d['mac'],
                'bytes_in'    => $d['bytes_in'],
                'bytes_out'   => $d['bytes_out'],
                'rate_in'     => $d['rate_in'],
                'rate_out'    => $d['rate_out'],
            ]);
        }
    }
}
