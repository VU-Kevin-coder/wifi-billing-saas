<?php

namespace App\Services\Policy;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The trusted policy schema is independent of values already in the database. */
class PolicyDefinitions
{
    public static function all(): array
    {
        $catalog = config('platform_control_settings');
        $definitions = [];
        foreach ($catalog['fields'] as $key => $field) {
            if (in_array($field['type'], ['policy', 'policy_select'], true)) {
                $definitions[$field['policy_key'] ?? $key] = $field;
            }
        }
        foreach ($catalog['lifecycle']['defaults'] as $state => $defaults) {
            foreach (array_keys($catalog['lifecycle']['actions']) as $index => $action) {
                if ($defaults[$index] !== null) {
                    $definitions["lifecycle.{$state}.{$action}"] = ['type' => 'policy', 'default' => $defaults[$index]];
                }
            }
        }

        return $definitions;
    }

    public static function normalize(string $key, mixed $value): mixed
    {
        $field = self::all()[$key] ?? null;
        if (! $field) {
            throw ValidationException::withMessages(['policies' => 'The submitted form contains an unknown policy.']);
        }
        $default = $field['default'];
        $rules = match (true) {
            is_bool($default) => ['required', 'boolean'],
            isset($field['options']) => ['required', 'string', \Illuminate\Validation\Rule::in($field['options'])],
            is_int($default) => array_filter(['required', 'integer', isset($field['min']) ? 'min:'.$field['min'] : null, isset($field['max']) ? 'max:'.$field['max'] : null]),
            is_float($default) => array_filter(['required', 'numeric', isset($field['min']) ? 'min:'.$field['min'] : null, isset($field['max']) ? 'max:'.$field['max'] : null]),
            default => ['required', 'string', 'max:255'],
        };
        $validated = Validator::make(['value' => $value], ['value' => $rules])->validate()['value'];

        return match (true) {
            is_bool($default) => filter_var($validated, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $validated,
            is_float($default) => (float) $validated,
            default => $validated,
        };
    }

    /** Do not silently reinterpret or submit damaged or unknown stored values. */
    public static function validStored(string $key, mixed $value): bool
    {
        $field = self::all()[$key] ?? null;
        if (! $field) return false;
        $default = $field['default'];
        if (is_bool($default) && ! is_bool($value)) return false;
        if (is_int($default) && ! is_int($value)) return false;
        if (is_float($default) && ! is_float($value) && ! is_int($value)) return false;
        if (is_string($default) && ! is_string($value)) return false;
        try {
            self::normalize($key, $value);
            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
