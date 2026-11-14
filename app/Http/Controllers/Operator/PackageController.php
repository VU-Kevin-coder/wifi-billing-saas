<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Services\Tenancy\TenantContext;
use App\Support\DataLimit;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index(TenantContext $context)
    {
        $currency = strtoupper((string) PlatformSetting::get('currency', 'UGX'));
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
            'mostPopular',
            'currency',
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:minutes,hours,days,weeks,months,years'],
            'speed_limit_mbps' => ['required', 'integer', 'min:1', 'max:'.(int) PlatformSetting::get('package.max_speed', 100)],
            'device_limit' => ['required', 'integer', 'min:1', 'max:'.(int) PlatformSetting::get('package.max_devices', 5)],
            'data_limit' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (! PlatformSetting::get('package.data_caps', true) && ! DataLimit::isUnlimited($value)) {
                    $fail('Data caps are disabled by the platform.');
                }
                if (! DataLimit::isValid($value)) {
                    $fail('Enter a data limit like 5GB or 500 MB, or leave it as Unlimited.');
                }
            }],
            'is_published' => ['boolean'],
            'is_popular' => ['boolean'],
            'features' => ['nullable', 'array'],
        ]);

        $validated['tenant_id'] = $context->getTenantId();
        $validated['features'] = $request->input('features', []);
        $validated['currency'] = strtoupper((string) PlatformSetting::get('currency', 'UGX'));

        $package = Package::create($validated);

        AuditLog::record('package.created', $package, [
            'name' => $package->name,
            'price' => (float) $package->price,
        ]);

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
            'duration_unit' => ['required', 'in:minutes,hours,days,weeks,months,years'],
            'speed_limit_mbps' => ['required', 'integer', 'min:1', 'max:'.(int) PlatformSetting::get('package.max_speed', 100)],
            'device_limit' => ['required', 'integer', 'min:1', 'max:'.(int) PlatformSetting::get('package.max_devices', 5)],
            'data_limit' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (! PlatformSetting::get('package.data_caps', true) && ! DataLimit::isUnlimited($value)) {
                    $fail('Data caps are disabled by the platform.');
                }
                if (! DataLimit::isValid($value)) {
                    $fail('Enter a data limit like 5GB or 500 MB, or leave it as Unlimited.');
                }
            }],
            'is_published' => ['boolean'],
            'is_popular' => ['boolean'],
            'features' => ['nullable', 'array'],
        ]);

        $validated['features'] = $request->input('features', []);

        $package->update($validated);

        $changes = array_diff_key($package->getChanges(), ['updated_at' => true, 'features' => true]);
        if ($changes) {
            AuditLog::record('package.updated', $package, [
                'changed' => array_keys($changes),
                'new_price' => array_key_exists('price', $changes) ? (float) $package->price : null,
            ]);
        }

        return redirect()->route('operator.packages.index')
            ->with('status', 'Package updated successfully.');
    }
}
