<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Services\Payments\PaymentGatewaySettings;
use App\Services\Policy\Policy;
use App\Services\Policy\PolicyDefinitions;
use App\Services\SmsConfiguration;
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
            'payoutsPaused' => (bool) PlatformSetting::get('payouts.paused', false),
            'roles' => $roles,
            'agents' => $agents,
            'health' => $health,
            'history' => $history,
            'section' => $request->query('section', 'general'),
            'smsStatus' => SmsConfiguration::displayStatus(PlatformSetting::get('sms_provider', 'africastalking')),
        ]);
    }

    public function update(Request $request)
    {
        $catalog = $this->catalog();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'settings' => ['nullable', 'array'],
            'policies' => ['nullable', 'array'],
        ]);

        if (mb_strlen(trim($validated['reason'])) < 5) {
            throw ValidationException::withMessages(['reason' => 'A reason of at least five non-space characters is required.']);
        }
        if ($request->exists('permissions') || $request->exists('permission_roles')) {
            throw ValidationException::withMessages([
                'permissions' => 'The permission matrix is not operative and cannot be edited here.',
            ]);
        }

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
            $normalized = PolicyDefinitions::normalize($storageKey, $value);
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

        DB::transaction(function () use (&$changes, $updates, $roleAssignments, $validated, $catalog, $values): void {
            $smsUpdates = array_filter($updates, fn ($update) => in_array($update[0], ['sms.provider', 'sms.username', 'sms.environment', 'sms.sender', 'sms.api_key'], true));
            if ($smsUpdates !== []) {
                SmsConfiguration::lock();
                $smsProvider = $values['sms.provider'] ?? null;
                $credentialUpdate = collect($smsUpdates)->first(fn ($update) => $update[0] === 'sms.api_key');
                $smsCredential = $credentialUpdate[2] ?? null;
                if (filled($smsCredential) && ! is_string($smsProvider)) {
                    throw ValidationException::withMessages(['settings.sms.provider' => 'Select the provider when updating its credential.']);
                }
                SmsConfiguration::validateProviderChange($smsProvider, $smsCredential, 'settings.sms.api_key');
                $providerUpdate = collect($smsUpdates)->first(fn ($update) => $update[0] === 'sms.provider');
                if ($smsProvider !== null && ! $providerUpdate && $smsProvider !== PlatformSetting::get('sms_provider', 'africastalking')) {
                    throw ValidationException::withMessages(['settings.sms.provider' => 'SMS configuration changed. Reload and try again.']);
                }
                if (filled($smsCredential)) {
                    PlatformSetting::set('sms_credential_provider', $smsProvider);
                }
            }
            foreach ($updates as [$key, $field, $value]) {
                $this->writeValue($key, $field, $value);
                if (in_array($key, ['sms.provider', 'sms.username', 'sms.environment', 'sms.sender', 'sms.api_key'], true)) {
                    PlatformSetting::set('sms_status', 'Configured (Untested)');
                }
                if (in_array($key, ['mail.host', 'mail.port', 'mail.username', 'mail.password', 'mail.from_address', 'mail.from_name'], true)) {
                    PlatformSetting::set('email_status', 'Configured (Untested)');
                }
            }
            if (array_intersect([
                'payment.active_gateway',
                'payment.obpay.secret_key',
                'payment.obpay.webhook_secret',
                'payment.pawapay.api_token',
                'payment.pawapay.environment',
                'payment.iotec.client_id',
                'payment.iotec.client_secret',
                'payment.iotec.wallet_id',
                'payment.iotec.webhook_secret',
            ], array_keys($values)) !== []) {
                $activeGateway = PlatformSetting::get('payment.active_gateway');
                if (is_string($activeGateway) && $activeGateway !== ''
                    && ! PaymentGatewaySettings::configured($activeGateway)) {
                    throw ValidationException::withMessages([
                        'settings.payment.active_gateway' => 'Configure all required credentials and callback secrets before enabling this gateway.',
                    ]);
                }
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

        $section = $request->input('section', 'general');
        $status = $changes === [] ? 'No settings changed.' : count($changes).' setting(s) saved.';
        if ($request->header('X-Settings-Async') === '1' && $request->expectsJson()) {
            $request->session()->flash('status', $status);

            return response()->json(['saved' => true, 'redirect' => route('platform.configuration.control', ['section' => $section]), 'message' => $status]);
        }

        return redirect()->route('platform.configuration.control', ['section' => $section])->with('status', $status);
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
        if (mb_strlen(trim($validated['reason'])) < 5) {
            throw ValidationException::withMessages(['reason' => 'A reason of at least five non-space characters is required.']);
        }
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
                'payment.iotec.webhook_url' => route('api.webhooks.iotec'),
                'payment.pawapay.webhook_url' => route('api.webhooks.pawapay'),
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

        return PlatformSetting::get($this->storageKey($key), $field['default']);
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
                PlatformSetting::setSecret($storageKey, $value);
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
            'select' => $key === 'payment.active_gateway'
                ? ['nullable', 'in:'.implode(',', $field['options'])]
                : ['required', 'in:'.implode(',', $field['options'])],
            'policy_select' => ['required', 'in:'.implode(',', $field['options'])],
            'textarea' => ['nullable', 'string', 'max:5000'],
            default => $key === 'payment.iotec.wallet_id'
                ? ['nullable', 'uuid']
                : array_filter(['required', 'string', isset($field['max']) ? 'max:'.$field['max'] : 'max:2048']),
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
        foreach (SiteAgent::query()->with(['site' => fn ($query) => $query->withoutTenancy()])->whereNull('revoked_at')->lockForUpdate()->get() as $agent) {
            $token = Str::random(64);
            $updated = SiteAgent::query()->whereKey($agent->id)->whereNull('revoked_at')
                ->update(['token_hash' => hash('sha256', $token), 'last_seen_at' => null]);
            if ($updated) {
                $rotated[] = ['site' => $agent->site?->name ?? 'Unknown site', 'token' => $token];
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
        $policyChanges = [];
        foreach ($catalog['fields'] as $key => $field) {
            if (in_array($field['type'], ['readonly', 'secret'], true)) {
                continue;
            }
            if (in_array($field['type'], ['policy', 'policy_select'], true)) {
                $storageKey = $field['policy_key'] ?? $key;
                $before = $this->readValue($key, $field);
                if ($before !== $field['default']) {
                    $policyChanges[$storageKey] = ['old' => $before, 'new' => $field['default']];
                }
            }
            $this->writeValue($key, $field, $field['default']);
        }

        return ['reset' => true, 'policy_changes' => $policyChanges];
    }
}
