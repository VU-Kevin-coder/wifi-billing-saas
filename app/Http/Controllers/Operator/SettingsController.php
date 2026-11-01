<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        $activeTab = $request->query('tab', 'general');

        abort_unless(in_array($activeTab, ['general', 'portal'], true), 404);

        $settings = $tenant->settings;

        return view('operator.settings.index', compact('tenant', 'settings', 'activeTab'));
    }

    public function updateGeneral(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'primary_color' => ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'support_phone' => ['nullable', 'string', 'max:20'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'domain' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'file', 'mimes:jpeg,png,jpg,svg', 'max:2048'],
        ]);

        $settings = $tenant->settings;
        $oldLogoPath = $settings['logo_path'] ?? null;

        if ($request->hasFile('logo')) {
            $settings['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $settings['brand_name'] = $validated['brand_name'];
        $settings['primary_color'] = $validated['primary_color'];
        $settings['support_phone'] = $validated['support_phone'] ?? null;
        $settings['support_email'] = $validated['support_email'] ?? null;
        $settings['whatsapp_number'] = $validated['whatsapp_number'] ?? null;
        $settings['domain'] = $validated['domain'];

        $tenant->update(['settings' => $settings]);

        if ($request->hasFile('logo') && $oldLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return redirect()->route('operator.settings.index', ['tenant' => $tenant->slug])
            ->with('status', 'General settings updated successfully.');
    }
}
