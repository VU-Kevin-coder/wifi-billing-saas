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

    /** Describe the usable credential without exposing it or hiding a failed test. */
    public static function displayStatus(string $provider): string
    {
        $lastStatus = PlatformSetting::get('sms_status', 'Not Tested');
        $failed = $lastStatus === 'Failed';
        if ($provider === 'disabled') {
            return $failed ? 'Disabled (last test failed)' : 'Disabled';
        }
        if (! in_array($provider, ['africastalking', 'twilio'], true)) {
            return $failed ? 'Failed — unsupported provider' : 'Unsupported provider';
        }
        if (! self::credentialFor($provider)) {
            $hasCredential = filled(PlatformSetting::getSecret('sms_api_key'));
            $problem = $hasCredential ? 'Credential re-entry required' : 'Not configured';

            return $failed ? 'Failed — '.$problem : $problem;
        }

        return $lastStatus;
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
        if ($provider === null) {
            return;
        }
        if ($provider === 'disabled') {
            if (filled($credential)) {
                throw ValidationException::withMessages([$errorKey => 'Select an active SMS provider before saving a credential.']);
            }

            return;
        }
        if ($provider === $current || ($current === 'disabled' && PlatformSetting::get('sms_credential_provider') === $provider)) {
            return;
        }
        $old = PlatformSetting::getSecret('sms_api_key');
        if (! is_string($credential) || trim($credential) === '' || (is_string($old) && hash_equals($old, $credential))) {
            throw ValidationException::withMessages([$errorKey => 'Changing SMS provider requires a new credential for that provider.']);
        }
    }
}
