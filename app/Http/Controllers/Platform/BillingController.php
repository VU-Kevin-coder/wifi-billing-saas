<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index()
    {
        // 1. Real KPI Calculations from Tenants
        $activeTenantsQuery = Tenant::where('subscription_status', 'active');
        $monthlyRecurring = (float) $activeTenantsQuery->sum('plan_fee');
        $activeCount = $activeTenantsQuery->count();

        $trialTenantsQuery = Tenant::where('subscription_status', 'trial');
        $trialCount = $trialTenantsQuery->count();
        $trialPipelineValue = (float) $trialTenantsQuery->sum('plan_fee');

        $pastDueTenants = Tenant::where('subscription_status', 'past_due')->get();
        $overdueFees = (float) $pastDueTenants->sum('plan_fee');
        $overdueCount = $pastDueTenants->count();

        // 2. Fetch all Plans with assigned tenant counts
        $plans = PlatformPlan::withCount('tenants')
            ->orderBy('price', 'asc')
            ->get();

        // 3. Fetch Invoices / Operator Subscriptions
        $invoices = Tenant::with('plan')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('platform.billing.index', compact(
            'monthlyRecurring',
            'activeCount',
            'trialCount',
            'trialPipelineValue',
            'overdueFees',
            'overdueCount',
            'pastDueTenants',
            'plans',
            'invoices'
        ));
    }

    public function storePlan(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:platform_plans,name',
            'price' => 'required|numeric|min:0',
            'trial_days' => 'required|integer|min:0|max:365',
            'max_customers' => 'required|integer|min:1',
            'max_routers' => 'required|integer|min:1',
            'features' => 'nullable|string',
        ]);

        $features = [];
        if (! empty($validated['features'])) {
            $features = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $validated['features']))));
        }

        $plan = PlatformPlan::create([
            'name' => $validated['name'],
            'price' => $validated['price'],
            'trial_days' => $validated['trial_days'],
            'max_customers' => $validated['max_customers'],
            'max_routers' => $validated['max_routers'],
            'features' => $features,
        ]);

        return redirect()->route('platform.billing.index')
            ->with('success', "Platform plan '{$plan->name}' created successfully.");
    }

    public function updatePlan(Request $request, PlatformPlan $plan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:platform_plans,name,'.$plan->id,
            'price' => 'required|numeric|min:0',
            'trial_days' => 'required|integer|min:0|max:365',
            'max_customers' => 'required|integer|min:1',
            'max_routers' => 'required|integer|min:1',
            'features' => 'nullable|string',
        ]);

        $features = [];
        if (! empty($validated['features'])) {
            $features = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $validated['features']))));
        }

        $plan->update([
            'name' => $validated['name'],
            'price' => $validated['price'],
            'trial_days' => $validated['trial_days'],
            'max_customers' => $validated['max_customers'],
            'max_routers' => $validated['max_routers'],
            'features' => $features,
        ]);

        return redirect()->route('platform.billing.index')
            ->with('success', "Platform plan '{$plan->name}' updated successfully.");
    }

    public function destroyPlan(PlatformPlan $plan)
    {
        $planName = $plan->name;
        $tenantCount = $plan->tenants()->count();

        // Unlink tenants so they are safely preserved
        if ($tenantCount > 0) {
            $plan->tenants()->update(['platform_plan_id' => null]);
        }

        $plan->delete();

        $msg = "Plan '{$planName}' deleted successfully.";
        if ($tenantCount > 0) {
            $msg .= " ({$tenantCount} operator tenant(s) were unlinked from this plan).";
        }

        return redirect()->route('platform.billing.index')->with('success', $msg);
    }
}
