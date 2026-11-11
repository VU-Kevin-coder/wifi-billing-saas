<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class TenantSettingsSecrets
{
    private const PREFIX = 'enc:v1:';

    private const SECRET_KEYS = [
        'sms_api_key',
        'whatsapp_api_key',
        'whatsapp_webhook_secret',
    ];

    public static function encrypt(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $settings[$key] ?? null;
            if (is_string($value) && $value !== '' && ! str_starts_with($value, self::PREFIX)) {
                $settings[$key] = self::PREFIX.Crypt::encryptString($value);
            }
        }

        return $settings;
    }

    public static function decrypt(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $settings[$key] ?? null;
            if (is_string($value) && str_starts_with($value, self::PREFIX)) {
                $settings[$key] = Crypt::decryptString(substr($value, strlen(self::PREFIX)));
            }
        }

        return $settings;
    }
}
