<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\PolicySetting;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Services\Policy\Policy;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ControlSettingsController extends Controller
{
    private function catalog(): array
    {
        return config('platform_control_settings');
    }

    public function index(Request $request)
    {
        $catalog = $this->catalog();
        $settings = [];

        foreach ($catalog['fields'] as $key => $field) {
            $settings[$key] = $this->readValue($key, $field);
        }

        $roles = $this->rolePermissions($catalog);
        $agents = SiteAgent::query()
            ->with(['site:id,name,tenant_id', 'tenant:id,name'])
            ->latest('last_seen_at')
            ->get();
        $health = app(SystemHealthService::class)->current();
        $history = AuditLog::query()
            ->with('user:id,name')
            ->whereIn('action', ['platform.settings.updated', 'platform.settings.action'])
            ->latest()
            ->limit(100)
            ->get();

        return view('platform.configuration.control-settings', [
            'catalog' => $catalog,
            'settings' => $settings,
            'roles' => $roles,
            'agents' => $agents,
            'health' => $health,
            'history' => $history,
            'section' => $request->query('section', 'general'),
        ]);
    }

    public function update(Request $request)
    {
        $catalog = $this->catalog();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'settings' => ['nullable', 'array'],
        ]);

        $definitions = $catalog['fields'];
        $values = $request->input('settings', []);
        $unknown = array_diff(array_keys($values), array_keys($definitions));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => 'The submitted form contains an unknown setting.',
            ]);
        }

        $changes = [];
        $updates = [];
        $roleAssignments = null;

        foreach ($values as $key => $value) {
            $field = $definitions[$key];
            if (in_array($field['type'], ['readonly', 'policy', 'policy_select'], true)) {
                continue;
            }

            $normalized = $this->validateField($key, $field, $value);
            $before = $this->readValue($key, $field);
            if ($field['type'] === 'secret' && $normalized === '') {
                continue;
            }
            if ($before === $normalized) {
                continue;
            }

            $updates[] = [$key, $field, $normalized];
            $changes[] = [
                'key' => $key,
                'old' => ($field['type'] === 'secret') ? '[redacted]' : $before,
                'new' => ($field['type'] === 'secret') ? '[redacted]' : $normalized,
                'source' => $field['source'] ?? 'database',
            ];
        }

        foreach ($request->input('policies', []) as $key => $value) {
            $field = $definitions[$key] ?? null;
            if (! $field || ! in_array($field['type'], ['policy', 'policy_select'], true)) {
                throw ValidationException::withMessages([
                    'policies' => 'The submitted form contains an unknown policy.',
                ]);
            }
            $storageKey = $field['policy_key'] ?? $key;
            $normalized = $this->validateField($key, $field, $value);
            $before = $this->readValue($key, $field);
            if ($before !== $normalized) {
                $changes[] = ['key' => $storageKey, 'old' => $before, 'new' => $normalized, 'source' => 'policy'];
                $updates[] = [$key, $field, $normalized];
            }
        }

        $roleValues = $request->input('permissions', []);
        $rolePresence = $request->input('permission_roles', []);
        if ($rolePresence !== []) {
            foreach (array_keys($rolePresence) as $roleName) {
                $roleValues[$roleName] = $roleValues[$roleName] ?? [];
            }
            $roleAssignments = $this->validateRolePermissions($roleValues, $catalog);
        }

        DB::transaction(function () use (&$changes, $updates, $roleAssignments, $validated, $catalog): void {
            foreach ($updates as [$key, $field, $value]) {
                $this->writeValue($key, $field, $value);
            }
            if ($roleAssignments !== null) {
                $this->saveRolePermissions($roleAssignments, $catalog);
                $changes[] = ['key' => 'role_permissions', 'old' => 'previous assignments', 'new' => 'updated assignments', 'source' => 'database'];
            }
            if ($changes !== []) {
                AuditLog::record('platform.settings.updated', null, [
                    'reason' => trim($validated['reason']),
                    'changes' => $changes,
                ]);
            }
        });

        return redirect()->route('platform.configuration.control', [
            'section' => $request->input('section', 'general'),
        ])->with('status', $changes === [] ? 'No settings changed.' : count($changes).' setting(s) saved.');
    }

    public function export()
    {
        $catalog = $this->catalog();
        $export = [];
        foreach ($catalog['fields'] as $key => $field) {
            $value = $this->readValue($key, $field);
            $export[$key] = $field['type'] === 'secret'
                ? ($value ? '[configured]' : null)
                : $value;
        }
        $export['roles'] = $this->rolePermissions($catalog);

        return response()->json($export)
            ->header('Content-Disposition', 'attachment; filename="platform-control-settings.json"');
    }

    public function action(Request $request, string $action)
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ]);
        $words = ['rotate' => 'ROTATE', 'purge' => 'PURGE', 'freeze' => 'FREEZE', 'resume' => 'RESUME', 'reset' => 'RESET'];
        abort_unless(isset($words[$action]) && hash_equals($words[$action], $validated['confirmation']), 422);

        $result = DB::transaction(function () use ($action, $validated): array {
            $details = match ($action) {
                'rotate' => $this->rotateAgentTokens(),
                'purge' => $this->purgeExpiredSessions(),
                'freeze' => $this->setPayoutsPaused(),
                'resume' => $this->setPayoutsPaused(false),
                'reset' => $this->resetSettings(),
            };
            AuditLog::record('platform.settings.action', null, [
                'action' => $action,
                'reason' => trim($validated['reason']),
                'details' => array_diff_key($details, ['tokens' => true]),
            ]);

            return $details;
        });

        return redirect()->route('platform.configuration.control', ['section' => 'danger'])
            ->with('status', ucfirst($action).' completed.')
            ->with('control_action_result', $result);
    }

    private function readValue(string $key, array $field): mixed
    {
        if (in_array($field['type'], ['policy', 'policy_select'], true)) {
            return Policy::get($field['policy_key'] ?? $key, $field['default']);
        }
        if ($field['type'] === 'readonly') {
            return match ($key) {
                'pay.pesapal.ipn' => route('api.webhooks.pesapal'),
                default => (string) ($field['default'] ?? ''),
            };
        }

        if ($field['type'] === 'secret') {
            $storageKey = $field['secret_key'] ?? $key;

            return (bool) (PlatformSetting::getSecret($storageKey) ?: PlatformSetting::get($storageKey));
        }

        if (! empty($field['planned'])) {
            return PlatformSetting::get('control.desired.'.$key, $field['default']);
        }

        if (($field['source'] ?? null) === 'env') {
            return PlatformSetting::get('control.desired.'.$key, $field['default']);
        }

        $storageKey = $this->storageKey($key);
        $value = PlatformSetting::get($storageKey, $field['default']);
        if (in_array($key, ['pay.pesapal.mode', 'pay.flw.mode', 'pay.mtn.mode', 'pay.airtel.mode'], true)) {
            return $value === 'production' ? 'live' : $value;
        }

        return $value;
    }

    private function writeValue(string $key, array $field, mixed $value): void
    {
        if ($field['type'] === 'policy' || $field['type'] === 'policy_select') {
            Policy::set($field['policy_key'] ?? $key, $value, $field['label']);

            return;
        }

        if ($field['type'] === 'secret') {
            if ($value !== '') {
                $storageKey = $field['secret_key'] ?? $key;
                if (in_array($storageKey, ['pesapal_consumer_key', 'flutterwave_public_key'], true)) {
                    PlatformSetting::set($storageKey, $value);
                } else {
                    PlatformSetting::setSecret($storageKey, $value);
                }
            }

            return;
        }

        if (! empty($field['planned'])) {
            PlatformSetting::set('control.desired.'.$key, $value);

            return;
        }

        if (($field['source'] ?? null) === 'env') {
            PlatformSetting::set('control.desired.'.$key, $value);

            return;
        }

        if (in_array($key, ['pay.pesapal.mode', 'pay.flw.mode', 'pay.mtn.mode', 'pay.airtel.mode'], true)) {
            $value = $value === 'live' ? 'production' : $value;
        }

        PlatformSetting::set($this->storageKey($key), $value);
    }

    private function storageKey(string $key): string
    {
        return [
            'app.name' => 'platform_name',
            'app.timezone' => 'timezone',
            'app.currency' => 'currency',
            'app.support_email' => 'support_email',
            'app.support_phone' => 'support_phone',
            'tenant.commission_rate' => 'default_commission_rate',
            'pay.pesapal.enabled' => 'pesapal_enabled',
            'pay.pesapal.mode' => 'pesapal_environment',
            'pay.pesapal.key' => 'pesapal_consumer_key',
            'pay.flw.enabled' => 'flutterwave_enabled',
            'pay.flw.mode' => 'flutterwave_environment',
            'pay.flw.public' => 'flutterwave_public_key',
            'pay.mtn.enabled' => 'mtn_enabled',
            'pay.mtn.mode' => 'mtn_environment',
            'pay.airtel.enabled' => 'airtel_enabled',
            'pay.airtel.mode' => 'airtel_environment',
            'sms.provider' => 'sms_provider',
            'sms.username' => 'sms_username',
            'sms.environment' => 'sms_environment',
            'sms.sender' => 'sms_sender_id',
            'mail.host' => 'smtp_host',
            'mail.port' => 'smtp_port',
            'mail.username' => 'smtp_username',
            'mail.password' => 'smtp_password',
            'mail.from_address' => 'mail_from_address',
            'mail.from_name' => 'mail_from_name',
        ][$key] ?? $key;
    }

    private function validateField(string $key, array $field, mixed $value): mixed
    {
        if ($field['type'] === 'secret') {
            Validator::make(['value' => $value], ['value' => ['nullable', 'string', 'max:4096']])->validate();

            return trim((string) $value);
        }

        if ($field['type'] === 'tags') {
            if (is_string($value)) {
                $value = preg_split('/\R/', $value) ?: [];
            }
            Validator::make(['value' => $value], ['value' => ['array'], 'value.*' => ['string', 'max:255']])->validate();
            if ($key === 'security.admin_ip_allow') {
                Validator::make(
                    ['value' => $value],
                    ['value.*' => [function (string $attribute, mixed $range, \Closure $fail): void {
                        if (! is_string($range)) {
                            $fail('Each allow-list entry must be an IPv4 or IPv6 address or CIDR range.');

                            return;
                        }

                        $parts = explode('/', trim($range), 2);
                        $address = $parts[0];
                        $validAddress = filter_var($address, FILTER_VALIDATE_IP);
                        $maxPrefix = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
                        $validPrefix = ! isset($parts[1])
                            || (ctype_digit($parts[1]) && (int) $parts[1] >= 0 && (int) $parts[1] <= $maxPrefix);
                        if ($validAddress === false || ! $validPrefix || count($parts) > 2) {
                            $fail('Each allow-list entry must be a valid IPv4 or IPv6 address or CIDR range.');
                        }
                    }]],
                )->validate();
            }
            if ($key === 'hotspot.default_dns') {
                Validator::make(
                    ['value' => $value],
                    ['value.*' => ['ip']],
                )->validate();
            }
            if ($key === 'hotspot.walled_garden') {
                Validator::make(
                    ['value' => $value],
                    ['value.*' => ['regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/']],
                )->validate();
            }
            if (isset($field['allowed_values'])) {
                Validator::make(
                    ['value' => $value],
                    ['value.*' => ['in:'.implode(',', $field['allowed_values'])]],
                )->validate();
            }

            return array_values(array_unique(array_filter(array_map('trim', $value), fn ($item) => $item !== '')));
        }

        $rules = match ($field['type']) {
            'boolean' => ['required', 'boolean'],
            'number', 'policy' => array_filter([
                'required',
                'numeric',
                isset($field['min']) ? 'min:'.$field['min'] : null,
                isset($field['max']) ? 'max:'.$field['max'] : null,
            ]),
            'email' => ['required', 'email', 'max:255'],
            'select', 'policy_select' => ['required', 'in:'.implode(',', $field['options'])],
            'textarea' => ['nullable', 'string', 'max:5000'],
            default => array_filter(['required', 'string', isset($field['max']) ? 'max:'.$field['max'] : 'max:2048']),
        };
        $validated = Validator::make(['value' => $value], ['value' => $rules])->validate()['value'];
        if ($key === 'hotspot.default_network') {
            $parts = explode('/', (string) $validated, 2);
            $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : -1;
            if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || $prefix < 8
                || $prefix > 30) {
                throw ValidationException::withMessages([
                    'value' => 'The default hotspot network must be a usable IPv4 CIDR between /8 and /30.',
                ]);
            }

            $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $networkAddress = (int) sprintf('%u', ip2long($parts[0]));
            if (($networkAddress & $mask) !== $networkAddress) {
                throw ValidationException::withMessages([
                    'value' => 'The default hotspot CIDR must use its network address.',
                ]);
            }
        }
        if ($key === 'hotspot.wan_interface' && ! preg_match('/^(ether|wlan)\d{1,2}$/', (string) $validated)) {
            throw ValidationException::withMessages([
                'value' => 'The WAN interface must be a valid RouterOS etherN or wlanN interface name.',
            ]);
        }

        return match ($field['type']) {
            'boolean' => filter_var($validated, FILTER_VALIDATE_BOOLEAN),
            'number', 'policy' => str_contains((string) $validated, '.') ? (float) $validated : (int) $validated,
            default => $validated,
        };
    }

    private function rolePermissions(array $catalog): array
    {
        $roles = [];
        foreach ($catalog['permissions'] as $roleName => $defaults) {
            $role = Role::findByName($roleName, 'web');
            $roles[$roleName] = $role->permissions->pluck('name')->all();
            if ($roles[$roleName] === [] && Permission::query()->doesntExist()) {
                $roles[$roleName] = $defaults;
            }
        }

        return $roles;
    }

    private function validateRolePermissions(array $input, array $catalog): array
    {
        $allowedRoles = array_keys($catalog['permissions']);
        $allowedPermissions = array_keys($catalog['permission_labels']);
        $result = [];
        foreach ($input as $role => $permissions) {
            abort_unless(in_array($role, $allowedRoles, true), 403);
            $result[$role] = array_values(array_intersect((array) $permissions, $allowedPermissions));
            if ($role === 'platform_admin') {
                $result[$role] = $allowedPermissions;
            }
        }

        return $result;
    }

    private function saveRolePermissions(array $assignments, array $catalog): void
    {
        foreach ($assignments as $roleName => $permissionNames) {
            $role = Role::findByName($roleName, 'web');
            if ($roleName === 'platform_admin') {
                continue;
            }
            $permissions = collect($permissionNames)->map(
                fn (string $name) => Permission::findOrCreate($name, 'web')
            );
            $role->syncPermissions($permissions);
        }
    }

    private function rotateAgentTokens(): array
    {
        $rotated = [];
        foreach (Site::query()->with('agent')->get() as $site) {
            $token = Str::random(64);
            $agent = $site->agent;
            if ($agent) {
                $agent->update(['token_hash' => hash('sha256', $token), 'last_seen_at' => null, 'revoked_at' => null]);
                $rotated[] = ['site' => $site->name, 'token' => $token];
            }
        }

        return ['rotated_agents' => count($rotated), 'tokens' => $rotated];
    }

    private function purgeExpiredSessions(): array
    {
        $days = max(30, (int) PlatformSetting::get('retention.sessions', 180));
        $deleted = Session::withoutTenancy()
            ->whereIn('status', ['expired', 'disconnected', 'cancelled'])
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        return ['deleted_sessions' => $deleted, 'retention_days' => $days];
    }

    private function setPayoutsPaused(bool $paused = true): array
    {
        PlatformSetting::set('payouts.paused', $paused);

        return ['payouts_paused' => $paused];
    }

    private function resetSettings(): array
    {
        $catalog = $this->catalog();
        foreach ($catalog['fields'] as $key => $field) {
            if (in_array($field['type'], ['readonly', 'secret'], true)) {
                continue;
            }
            $this->writeValue($key, $field, $field['default']);
        }
        foreach (PolicySetting::query()->get() as $setting) {
            $default = $catalog['fields'][$setting->key]['default'] ?? null;
            if ($default !== null) {
                Policy::set($setting->key, $default, $setting->description);
            }
        }
        PlatformSetting::set('payouts.paused', false);

        return ['reset' => true];
    }
}
