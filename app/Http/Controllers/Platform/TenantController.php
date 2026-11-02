<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function index()
    {
        // 1. Global KPIs
        $totalOperators    = Tenant::count();
        $managedRouters    = Router::withoutGlobalScopes()->count();
        $onlineRouters     = Router::withoutGlobalScopes()->where('status', 'online')->count();
        $offlineRouters    = $managedRouters - $onlineRouters;
        $endCustomers      = Customer::withoutGlobalScopes()->count();
        $trialsInProgress  = Tenant::where('created_at', '>=', now()->subDays(14))->count();
        $activeOperators   = Tenant::where('status', 'active')->count();
        $suspendedOperators = Tenant::where('status', 'suspended')->count();
        // Trials ending in the next 7 days
        $trialsEndingSoon  = Tenant::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();

        // 2. Fetch Tenants with counts
        $tenants = Tenant::withCount([
            'users as staff_count',
            'routers as router_count',
        ])->orderBy('created_at', 'desc')->paginate(10);

        // 3. Attach additional metrics to each tenant
        foreach ($tenants as $tenant) {
            // Package usage
            $packageCount  = Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
            $customerCount = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();

            $tenant->package_usage      = "{$customerCount} / {$packageCount}";
            $tenant->package_percentage = $packageCount > 0 ? round(($customerCount / $packageCount) * 100) : 0;

            // Router health
            $tenantOnline         = Router::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'online')->count();
            $tenant->router_health  = "{$tenantOnline} / {$tenant->router_count}";
            $tenant->router_healthy = ($tenant->router_count === 0) || ($tenantOnline === $tenant->router_count);

            // Account status
            $tenant->account_status = $tenant->created_at->diffInDays(now()) < 14 ? 'Trial' : 'Active';

            // Plan from settings
            $tenant->plan = $tenant->settings['plan'] ?? 'Growth';
        }

        return view('platform.tenants.index', compact(
            'totalOperators', 'managedRouters', 'onlineRouters', 'offlineRouters',
            'endCustomers', 'trialsInProgress', 'trialsEndingSoon',
            'activeOperators', 'suspendedOperators', 'tenants'
        ));
    }

    public function show(Tenant $tenant)
    {
        // 1. KPI Metrics
        $customerCount = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $packageCount  = Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $routerCount   = Router::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $onlineRouters = Router::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'online')->count();

        // 2. Staff list
        $staff = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('roles')->get();

        // 3. Recent sessions (live activity)
        $recentActivity = \App\Models\Session::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'package'])
            ->latest('started_at')
            ->take(5)
            ->get();

        return view('platform.tenants.show', compact(
            'tenant', 'customerCount', 'packageCount',
            'routerCount', 'onlineRouters', 'staff', 'recentActivity'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:tenants,slug|alpha_dash',
            'plan' => 'required|string|in:Starter,Growth,Enterprise',
            'status' => 'required|string|in:trial,active,suspended',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|max:255|unique:users,email',
            'owner_password' => 'nullable|string|min:6',
        ]);

        $settings = [
            'plan' => $validated['plan'],
            'brand_name' => $validated['name'],
        ];

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'status' => $validated['status'] === 'trial' ? 'active' : $validated['status'],
            'settings' => $settings,
            'created_at' => $validated['status'] === 'trial' ? now() : now()->subDays(15),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['owner_name'],
            'email' => $validated['owner_email'],
            'password' => Hash::make($validated['owner_password'] ?: 'password'),
        ]);
        $user->email_verified_at = now();
        $user->save();

        $user->syncRoles(['operator_admin']);

        return redirect()->route('platform.tenants.index')
            ->with('success', "Operator tenant '{$tenant->name}' created successfully with owner {$user->email}.");
    }
}

