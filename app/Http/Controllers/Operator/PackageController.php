<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index(TenantContext $context)
    {
        // Automatically scoped to current tenant by BelongsToTenant
        $packages = Package::orderBy('price')->get();
        
        // Calculate KPIs for the top cards
        $publishedCount = $packages->where('is_published', true)->count();
        // Placeholder for now: active subscriptions will come from customers module later
        $activeSubscriptions = 0; 
        
        // Determine most popular (hardcoded logic for now, will be driven by real data later)
        $mostPopular = $packages->firstWhere('is_popular', true) ?? $packages->first();
        
        return view('operator.packages.index', compact(
            'packages', 
            'publishedCount', 
            'activeSubscriptions', 
            'mostPopular'
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:hours,days,months'],
            'speed_limit_mbps' => ['required', 'integer', 'min:1'],
            'device_limit' => ['required', 'integer', 'min:1'],
            'data_limit' => ['nullable', 'string', 'max:50'],
            'radius_profile_name' => ['nullable', 'string', 'max:50'],
            'is_published' => ['boolean'],
            'is_popular' => ['boolean'],
            'features' => ['nullable', 'array'],
        ]);

        $validated['tenant_id'] = $context->getTenantId();
        $validated['features'] = $request->input('features', []);
        $validated['currency'] = 'UGX'; // Hardcoded for now, will use Tenant settings later

        Package::create($validated);

        return redirect()->route('operator.packages.index')
            ->with('status', 'Package created successfully.');
    }

    public function update(Request $request, string $tenant, Package $package, TenantContext $context)
    {
        // Route model binding auto-checks tenant_id because of BelongsToTenant
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:hours,days,months'],
            'speed_limit_mbps' => ['required', 'integer', 'min:1'],
            'device_limit' => ['required', 'integer', 'min:1'],
            'data_limit' => ['nullable', 'string', 'max:50'],
            'radius_profile_name' => ['nullable', 'string', 'max:50'],
            'is_published' => ['boolean'],
            'is_popular' => ['boolean'],
            'features' => ['nullable', 'array'],
        ]);

        $validated['features'] = $request->input('features', []);

        $package->update($validated);

        return redirect()->route('operator.packages.index')
            ->with('status', 'Package updated successfully.');
    }
}
