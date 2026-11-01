<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class PortalSettingsController extends Controller
{
    public function edit(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        return redirect()->route('operator.settings.index', [
            'tenant' => $tenantModel->slug,
            'tab' => 'portal',
        ]);
    }

    public function update(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        $validated = $request->validate([
            'primary_color' => ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'template' => ['required', 'string'],
            'portal_title' => ['nullable', 'string', 'max:100'],
            'portal_subtitle' => ['nullable', 'string', 'max:255'],
            'show_packages' => ['boolean'],
            'show_device_registration' => ['boolean'],
            'show_troubleshooting' => ['boolean'],
            'support_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $validated['show_packages'] = $request->boolean('show_packages');
        $validated['show_device_registration'] = $request->boolean('show_device_registration', true);
        $validated['show_troubleshooting'] = $request->boolean('show_troubleshooting', true);

        // Merge with existing settings so we don't overwrite unexpected keys
        $currentSettings = $tenantModel->settings;
        $newSettings = array_merge($currentSettings, $validated);

        $tenantModel->update(['settings' => $newSettings]);

        return redirect()->route('operator.settings.index', [
            'tenant' => $tenantModel->slug,
            'tab' => 'portal',
        ])
            ->with('status', 'Captive portal settings updated successfully.');
    }
}
