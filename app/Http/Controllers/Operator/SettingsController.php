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

        abort_unless(in_array($activeTab, ['general', 'hotspot', 'portal'], true), 404);

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

    public function updateHotspot(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        $validated = $request->validate([
            'customer_id_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'voucher_prefix' => ['nullable', 'string', 'max:10'],
            'voucher_length' => ['required', 'integer', 'in:6,8,10'],
            'printed_voucher_length' => ['required', 'integer', 'in:6,8'],
            'voucher_format' => ['required', 'in:alphanumeric,numeric'],
            'prune_inactive_days' => ['required', 'integer', 'in:0,30,60,90'],
            'auto_reconnect_method' => ['required', 'in:mac_cookie,ip_binding'],
            'device_remember_days' => ['required', 'integer', 'in:7,30,60'],
            'enable_agent_portal' => ['sometimes', 'boolean'],
            'enable_agent_reselling' => ['sometimes', 'boolean'],
            'enable_pppoe_self_registration' => ['sometimes', 'boolean'],
            'block_tethering' => ['sometimes', 'boolean'],
            'tethering_action' => ['required', 'in:block,log'],
            'extend_gateway_fee' => ['sometimes', 'boolean'],
        ]);

        foreach ([
            'enable_agent_portal',
            'enable_agent_reselling',
            'enable_pppoe_self_registration',
            'block_tethering',
            'extend_gateway_fee',
        ] as $setting) {
            $validated[$setting] = $request->boolean($setting);
        }

        $tenant->update([
            'settings' => array_merge($tenant->settings, $validated),
        ]);

        return redirect()->route('operator.settings.index', [
            'tenant' => $tenant->slug,
            'tab' => 'hotspot',
        ])->with('status', 'Hotspot settings updated successfully.');
    }
}
