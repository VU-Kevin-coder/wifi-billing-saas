<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PolicySetting;
use App\Services\Policy\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PolicyController extends Controller
{
    public function index()
    {
        $catalog = config('platform_control_settings');
        $policies = PolicySetting::query()->orderBy('key')->get();
        $history = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'like', 'policy.%')
            ->latest()
            ->limit(100)
            ->get();
        $defaults = [];
        foreach ($catalog['fields'] as $key => $field) {
            if (in_array($field['type'], ['policy', 'policy_select'], true)) {
                $defaults[$key] = $field['default'];
            }
        }
        foreach ($catalog['lifecycle']['states'] as $state => $label) {
            foreach (array_keys($catalog['lifecycle']['actions']) as $index => $action) {
                $default = $catalog['lifecycle']['defaults'][$state][$index];
                if ($default !== null) {
                    $defaults["lifecycle.{$state}.{$action}"] = $default;
                }
            }
        }

        return view('platform.configuration.policy-center', compact('catalog', 'policies', 'history', 'defaults'));
    }

    public function updateAll(Request $request)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'policies' => ['required', 'array'],
        ]);
        $known = PolicySetting::query()->get()->keyBy('key');
        $catalog = config('platform_control_settings');
        $changes = [];

        foreach ($validated['policies'] as $key => $value) {
            $setting = $known->get($key);
            if (! $setting) {
                throw ValidationException::withMessages(['policies' => 'The submitted form contains an unknown policy.']);
            }
            $field = $catalog['fields'][$key] ?? null;
            $rules = match (true) {
                is_bool($setting->value) => ['required', 'boolean'],
                isset($field['options']) => ['required', 'in:'.implode(',', $field['options'])],
                is_numeric($setting->value) => array_filter([
                    'required',
                    'numeric',
                    isset($field['min']) ? 'min:'.$field['min'] : null,
                    isset($field['max']) ? 'max:'.$field['max'] : null,
                ]),
                default => ['required', 'string', 'max:255'],
            };
            $normalized = Validator::make(['value' => $value], ['value' => $rules])->validate()['value'];
            if (is_bool($setting->value)) {
                $normalized = filter_var($normalized, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_numeric($setting->value)) {
                $normalized = str_contains((string) $normalized, '.') ? (float) $normalized : (int) $normalized;
            }

            if ($normalized !== $setting->value) {
                $changes[$setting->category][$key] = [
                    'old' => $setting->value,
                    'new' => $normalized,
                    'description' => $setting->description,
                ];
            }
        }

        DB::transaction(function () use ($changes, $validated): void {
            foreach ($changes as $category => $items) {
                foreach ($items as $key => $change) {
                    Policy::set($key, $change['new'], $change['description']);
                }
                AuditLog::record("policy.{$category}.updated", null, [
                    'reason' => trim($validated['reason']),
                    'keys' => array_keys($items),
                    'changes' => $items,
                ]);
            }
        });

        return redirect()->route('platform.policies.index')
            ->with('status', $changes === [] ? 'No policies changed.' : 'Saved '.array_sum(array_map('count', $changes)).' policy change(s).');
    }

    public function export()
    {
        return response()->json(PolicySetting::query()->orderBy('key')->pluck('value', 'key'))
            ->header('Content-Disposition', 'attachment; filename="platform-policies.json"');
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
            if (! str_starts_with($key, $category.'.') && ! ($category === 'voucher' && str_starts_with($key, 'schedule.'))) {
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

        return back()->with('status', ucfirst($category).' policies updated.');
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
