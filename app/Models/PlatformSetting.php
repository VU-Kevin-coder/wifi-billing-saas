<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get a setting value by key with optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting || is_null($setting->value)) {
            return $default;
        }

        $value = $setting->value;

        // Boolean string conversion
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // JSON decode if applicable (e.g. arrays)
        if ((str_starts_with($value, '{') && str_ends_with($value, '}')) ||
            (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Set or update a setting by key.
     */
    public static function set(string $key, mixed $value): static
    {
        if (is_bool($value)) {
            $storeValue = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $storeValue = json_encode($value);
        } else {
            $storeValue = (string) $value;
        }

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $storeValue]
        );
    }

    public static function getSecret(string $key, mixed $default = null): mixed
    {
        $value = static::where('key', $key)->value('value');

        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public static function setSecret(string $key, string $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString($value)]
        );
    }
}
