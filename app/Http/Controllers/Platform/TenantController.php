<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformPlan;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index()
    {
        // 1. Global KPIs
        $totalOperators    = Tenant::count();
        $managedRouters    = Router::withoutTenancy()->count();
        $onlineRouters     = Router::withoutTenancy()->where('status', 'online')->count();
        $offlineRouters    = $managedRouters - $onlineRouters;
        $endCustomers      = Customer::withoutTenancy()->count();
        $trialsInProgress  = Tenant::where('created_at', '>=', now()->subDays(14))->count();
        $activeOperators   = Tenant::where('status', 'active')->count();
        $suspendedOperators = Tenant::where('status', 'suspended')->count();
        // Trials ending in the next 7 days
        $trialsEndingSoon  = Tenant::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();

        // 2. Fetch Tenants with counts and eager-loaded plan
        $tenants = Tenant::with(['plan', 'users'])
            ->withCount([
                'users as staff_count',
                'routers as router_count',
            ])->orderBy('created_at', 'desc')->paginate(10);

        // Fetch dynamic platform plans
        $plans = \App\Models\PlatformPlan::orderBy('price', 'asc')->get();

        // 3. Attach additional metrics to each tenant
        foreach ($tenants as $tenant) {
            // Package usage
            $packageCount  = Package::withoutTenancy()->where('tenant_id', $tenant->id)->count();
            $customerCount = Customer::withoutTenancy()->where('tenant_id', $tenant->id)->count();

            $tenant->package_usage      = "{$customerCount} / {$packageCount}";
            $tenant->package_percentage = $packageCount > 0 ? round(($customerCount / $packageCount) * 100) : 0;

            // Router health
            $tenantOnline         = Router::withoutTenancy()->where('tenant_id', $tenant->id)->where('status', 'online')->count();
            $tenant->router_health  = "{$tenantOnline} / {$tenant->router_count}";
            $tenant->router_healthy = ($tenant->router_count === 0) || ($tenantOnline === $tenant->router_count);

            // Account status
            $tenant->account_status = $tenant->created_at->diffInDays(now()) < 14 ? 'Trial' : 'Active';
        }

        return view('platform.tenants.index', compact(
            'totalOperators', 'managedRouters', 'onlineRouters', 'offlineRouters',
            'endCustomers', 'trialsInProgress', 'trialsEndingSoon',
            'activeOperators', 'suspendedOperators', 'tenants', 'plans'
        ));
    }

    public function show(Tenant $tenant)
    {
        $tenant->load('plan');

        $stats = [
            'customers' => Customer::withoutTenancy()->where('tenant_id', $tenant->id)->count(),
            'active_sessions' => Session::withoutTenancy()->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'monthly_revenue' => (float) Transaction::withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->where('type', 'voucher_sale')
                ->where('status', 'completed')
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount'),
            'routers_total' => Router::withoutTenancy()->where('tenant_id', $tenant->id)->count(),
            'routers_online' => Router::withoutTenancy()->where('tenant_id', $tenant->id)->where('status', 'online')->count(),
        ];

        $customerCount = Customer::withoutTenancy()->where('tenant_id', $tenant->id)->count();
        $packageCount  = Package::withoutTenancy()->where('tenant_id', $tenant->id)->count();
        $routerCount   = Router::withoutTenancy()->where('tenant_id', $tenant->id)->count();
        $onlineRouters = Router::withoutTenancy()->where('tenant_id', $tenant->id)->where('status', 'online')->count();
        $plans = PlatformPlan::orderBy('price')->get();

        $staff = User::withoutTenancy()->where('tenant_id', $tenant->id)->with('roles')->get();

        $recentActivity = Session::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'package'])
            ->latest('started_at')
            ->take(5)
            ->get();

        return view('platform.tenants.show', compact(
            'tenant', 'stats', 'plans', 'customerCount', 'packageCount',
            'routerCount', 'onlineRouters', 'staff', 'recentActivity'
        ));
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant->update($validated);

        \App\Models\AuditLog::record('tenant.updated', $tenant, ['fields' => array_keys($validated)], null, $tenant);

        return back()->with('status', 'Tenant updated successfully.');
    }

    public function changePlan(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'platform_plan_id' => ['required', 'exists:platform_plans,id'],
            'subscription_status' => ['required', Rule::in(['trial', 'active', 'past_due', 'suspended'])],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        $plan = PlatformPlan::findOrFail($validated['platform_plan_id']);

        $tenant->update([
            'platform_plan_id' => $plan->id,
            'plan' => $plan->name,
            'plan_fee' => $plan->price,
            'subscription_status' => $validated['subscription_status'],
            'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
        ]);

        \App\Models\AuditLog::record('tenant.plan_changed', $tenant, [
            'new_plan' => $plan->name,
            'new_status' => $validated['subscription_status'],
        ], null, $tenant);

        return back()->with('status', "Plan changed to {$plan->name}.");
    }

    public function store(Request $request)
    {
        // Validate the exact value that will be stored.
        $request->merge(['slug' => Tenant::normalizeSlug($request->input('slug'))]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:tenants,slug', Rule::notIn(Tenant::reservedSlugs()),
            ],
            'platform_plan_id' => 'nullable|exists:platform_plans,id',
            'plan' => 'nullable|string',
            'status' => 'required|string|in:trial,active,suspended',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|max:255|unique:users,email',
        ]);

        // Resolve plan dynamically from DB
        $plan = null;
        if (!empty($validated['platform_plan_id'])) {
            $plan = \App\Models\PlatformPlan::find($validated['platform_plan_id']);
        } elseif (!empty($validated['plan'])) {
            $plan = \App\Models\PlatformPlan::where('name', $validated['plan'])->first();
        }
        $plan ??= \App\Models\PlatformPlan::orderBy('price', 'asc')->first();

        $settings = [
            'brand_name' => $validated['name'],
        ];

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => $validated['status'] === 'trial' ? 'active' : $validated['status'],
            'platform_plan_id' => $plan?->id,
            'plan' => $plan?->name ?? $validated['plan'] ?? 'Starter',
            'plan_fee' => $plan?->price ?? 0,
            'subscription_status' => $validated['status'] === 'trial' ? 'trial' : 'active',
            'trial_ends_at' => $validated['status'] === 'trial' ? now()->addDays($plan?->trial_days ?: 14) : null,
            'settings' => $settings,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['owner_name'],
            'email' => $validated['owner_email'],
            // The owner sets their own password through the emailed reset link.
            'password' => Hash::make(\Illuminate\Support\Str::random(64)),
        ]);
        $user->syncRoles(['operator_admin']);

        $status = \Illuminate\Support\Facades\Password::broker()->sendResetLink(['email' => $user->email]);

        \App\Models\AuditLog::record('tenant.created', $tenant, [
            'slug' => $tenant->slug,
            'owner_email' => $user->email,
            'status' => $tenant->status,
        ], null, $tenant);

        $invited = $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT;

        return redirect()->route('platform.tenants.index')
            ->with('success', $invited
                ? "Operator tenant '{$tenant->name}' created. An invitation was emailed to {$user->email} so they can set their own password."
                : "Operator tenant '{$tenant->name}' created, but the invitation email to {$user->email} could not be sent. Ask them to use \"Forgot password\" to set a password.");
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);

        \App\Models\AuditLog::record('tenant.suspended', $tenant, ['by' => auth()->id()], null, $tenant);

        return back()->with('status', "Tenant '{$tenant->name}' suspended.");
    }

    public function reactivate(Tenant $tenant)
    {
        $tenant->update(['status' => 'active']);

        \App\Models\AuditLog::record('tenant.reactivated', $tenant, ['by' => auth()->id()], null, $tenant);

        return back()->with('status', "Tenant '{$tenant->name}' reactivated.");
    }

    public function verifyKyc(Tenant $tenant)
    {
        $tenant->update(['kyc_status' => 'verified']);

        \App\Models\AuditLog::record('tenant.kyc_verified', $tenant, ['by' => auth()->id()], null, $tenant);

        return back()->with('status', "Tenant '{$tenant->name}' KYC verified.");
    }

    public function rejectKyc(Tenant $tenant)
    {
        $tenant->update(['kyc_status' => 'rejected']);

        \App\Models\AuditLog::record('tenant.kyc_rejected', $tenant, ['by' => auth()->id()], null, $tenant);

        return back()->with('status', "Tenant '{$tenant->name}' KYC rejected.");
    }
}
