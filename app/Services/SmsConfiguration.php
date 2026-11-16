<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class SmsConfiguration
{
    /** Read provider, credential and its binding in a single database snapshot. */
    public static function credentialFor(string $provider): ?string
    {
        $values = PlatformSetting::whereIn('key', ['sms_provider', 'sms_api_key', 'sms_credential_provider'])->pluck('value', 'key');
        $current = $values['sms_provider'] ?? 'africastalking';
        $binding = $values['sms_credential_provider'] ?? null; // Legacy credentials need explicit re-entry before dispatch.
        $value = $values['sms_api_key'] ?? null;
        if ($current !== $provider || $binding !== $provider || ! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    /** Serialize settings writes, including the first provider switch. */
    public static function lock(): void
    {
        PlatformSetting::firstOrCreate(['key' => 'sms_config_lock'], ['value' => '1']);
        PlatformSetting::where('key', 'sms_config_lock')->lockForUpdate()->firstOrFail();
    }

    /** A credential belongs to the provider for which it was supplied. */
    public static function validateProviderChange(?string $provider, mixed $credential, string $errorKey): void
    {
        $current = PlatformSetting::get('sms_provider', 'africastalking');
        if ($provider === null || $provider === $current || $provider === 'disabled') {
            return;
        }
        $old = PlatformSetting::getSecret('sms_api_key');
        if (! is_string($credential) || trim($credential) === '' || (is_string($old) && hash_equals($old, $credential))) {
            throw ValidationException::withMessages([$errorKey => 'Changing SMS provider requires a new credential for that provider.']);
        }
    }
}
