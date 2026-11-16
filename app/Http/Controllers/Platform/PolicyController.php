<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PolicySetting;
use App\Services\Policy\Policy;
use App\Services\Policy\PolicyDefinitions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $definitions = PolicyDefinitions::all();
        $defaults = array_map(fn ($field) => $field['default'], $definitions);
        foreach ($catalog['lifecycle']['states'] as $state => $label) {
            foreach (array_keys($catalog['lifecycle']['actions']) as $index => $action) {
                $default = $catalog['lifecycle']['defaults'][$state][$index];
                if ($default !== null) {
                    $defaults["lifecycle.{$state}.{$action}"] = $default;
                }
            }
        }

        return view('platform.configuration.policy-center', compact('catalog', 'policies', 'history', 'defaults', 'definitions'));
    }

    public function updateAll(Request $request)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'policies' => ['required', 'array'],
        ]);
        if (mb_strlen(trim($validated['reason'])) < 5) {
            throw ValidationException::withMessages(['reason' => 'A reason of at least five non-space characters is required.']);
        }
        $known = PolicySetting::query()->get()->keyBy('key');
        $changes = [];

        foreach ($validated['policies'] as $key => $value) {
            $setting = $known->get($key);
            if (! $setting) {
                throw ValidationException::withMessages(['policies' => 'The submitted form contains an unknown policy.']);
            }
            $normalized = PolicyDefinitions::normalize($key, $value);

            if ($normalized !== $setting->value) {
                $changes[explode('.', $key)[0]][$key] = [
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
        $validated = $request->validate(['policies' => ['required', 'array']]);
        foreach (array_keys($validated['policies']) as $key) {
            if (! str_starts_with($key, 'lifecycle.') || ! isset(PolicyDefinitions::all()[$key])) {
                throw ValidationException::withMessages(['policies' => 'Unknown lifecycle policy.']);
            }
        }

        return $this->updateAll($request);
    }

    public function updateCategory(Request $request, string $category)
    {
        $validated = $request->validate(['policies' => ['required', 'array']]);
        $allowedCategories = ['approval', 'security', 'wallet', 'provisioning', 'voucher', 'schedule'];
        if (! in_array($category, $allowedCategories, true)) {
            throw ValidationException::withMessages(['policies' => 'Unknown policy category.']);
        }
        foreach (array_keys($validated['policies']) as $key) {
            if (! isset(PolicyDefinitions::all()[$key]) || (! str_starts_with($key, $category.'.') && ! ($category === 'voucher' && str_starts_with($key, 'schedule.')))) {
                throw ValidationException::withMessages(['policies' => 'Unknown policy or wrong category.']);
            }
        }

        return $this->updateAll($request);
    }

    public function updateApproval(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', 'string'],
            'threshold' => ['required'],
            'self_approve' => ['required', 'boolean'],
            'expiry_hours' => ['required'],
            'min_admins' => ['required'],
        ]);
        $policies = [];
        foreach ($validated as $key => $value) {
            $policies['approval.wallet_adjustment.'.$key] = $value;
        }
        $request->merge(['policies' => $policies]);

        return $this->updateAll($request);
    }
}
