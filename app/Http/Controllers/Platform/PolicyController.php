<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PolicySetting;
use App\Services\Policy\Policy;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function index()
    {
        $lifecyclePolicies = PolicySetting::where('category', 'lifecycle')
            ->orderBy('key')
            ->get()
            ->groupBy(fn ($p) => explode('.', $p->key)[1]);

        $categories = [
            'security'     => PolicySetting::where('category', 'security')->orderBy('key')->get(),
            'wallet'       => PolicySetting::where('category', 'wallet')->orderBy('key')->get(),
            'provisioning' => PolicySetting::where('category', 'provisioning')->orderBy('key')->get(),
            'voucher'      => PolicySetting::where('category', 'voucher')->orderBy('key')->get(),
            'schedule'     => PolicySetting::where('category', 'schedule')->orderBy('key')->get(),
            'approval'     => PolicySetting::where('category', 'approval')->orderBy('key')->get(),
        ];

        $approval = PolicySetting::where('category', 'approval')
            ->orderBy('key')
            ->get()
            ->keyBy('key');

        return view('platform.configuration.policies', compact('lifecyclePolicies', 'categories', 'approval'));
    }

    public function updateLifecycle(Request $request)
    {
        $validated = $request->validate([
            'policies' => 'required|array',
            'policies.*' => 'boolean',
        ]);

        foreach ($validated['policies'] as $key => $value) {
            Policy::set($key, (bool) $value);
        }

        AuditLog::record('policy.lifecycle.updated', null, [
            'keys' => array_keys($validated['policies']),
        ]);

        return back()->with('status', 'Lifecycle policies updated.');
    }

    public function updateCategory(Request $request, string $category)
    {
        $validated = $request->validate([
            'policies' => 'required|array',
        ]);

        foreach ($validated['policies'] as $key => $value) {
            if (! str_starts_with($key, $category . '.') && ! ($category === 'voucher' && str_starts_with($key, 'schedule.'))) {
                abort(403, 'Policy does not belong to this category.');
            }
            // Coerce based on existing type
            $existing = PolicySetting::where('key', $key)->first();
            $value = match (true) {
                is_bool($existing?->value) => (bool) $value,
                is_numeric($existing?->value) => $value + 0,
                default => (string) $value,
            };
            Policy::set($key, $value);
        }

        AuditLog::record("policy.{$category}.updated", null, ['keys' => array_keys($validated['policies'])]);

        return back()->with('status', ucfirst($category) . ' policies updated.');
    }

    public function updateApproval(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'boolean',
            'mode' => 'required|in:always,threshold,never',
            'threshold' => 'required|numeric|min:0',
            'self_approve' => 'boolean',
            'expiry_hours' => 'required|integer|min:1|max:168',
            'min_admins' => 'required|integer|min:1|max:10',
        ]);

        Policy::set('approval.wallet_adjustment.enabled', (bool) $request->boolean('enabled'));
        Policy::set('approval.wallet_adjustment.mode', $validated['mode']);
        Policy::set('approval.wallet_adjustment.threshold', (float) $validated['threshold']);
        Policy::set('approval.wallet_adjustment.self_approve', (bool) $request->boolean('self_approve'));
        Policy::set('approval.wallet_adjustment.expiry_hours', (int) $validated['expiry_hours']);
        Policy::set('approval.wallet_adjustment.min_admins', (int) $validated['min_admins']);

        AuditLog::record('policy.approval.updated', null, ['mode' => $validated['mode']]);

        return back()->with('status', 'Approval policies updated.');
    }
}
