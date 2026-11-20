<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;

final class PaymentGatewaySettings
{
    public const PROVIDERS = ['obpay', 'pawapay', 'iotec'];

    public static function active(): ?string
    {
        $provider = PlatformSetting::get('payment.active_gateway');

        return is_string($provider) && in_array($provider, self::PROVIDERS, true)
            ? $provider
            : null;
    }

    public static function configured(string $provider): bool
    {
        return match ($provider) {
            'obpay' => self::obpayMissingRequirements() === [],
            'pawapay' => filled(PlatformSetting::getSecret('payment.pawapay.api_token')),
            'iotec' => filled(PlatformSetting::getSecret('payment.iotec.client_id'))
                && filled(PlatformSetting::getSecret('payment.iotec.client_secret'))
                && filled(PlatformSetting::get('payment.iotec.wallet_id'))
                && filled(PlatformSetting::getSecret('payment.iotec.webhook_secret')),
            default => false,
        };
    }

    public static function obpaySecretKey(): ?string
    {
        return self::obpaySecret('payment.obpay.secret_key', 'services.obpay.secret_key');
    }

    public static function obpayWebhookSecret(): ?string
    {
        return self::obpaySecret('payment.obpay.webhook_secret', 'services.obpay.webhook_secret');
    }

    public static function obpayBaseUrl(): string
    {
        $url = trim((string) config('services.obpay.base_url', 'https://obpay.online/api/public/v1'));
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new \RuntimeException('OBPay base URL must be a valid HTTPS URL.');
        }

        return rtrim($url, '/');
    }

    public static function obpayCallbackUrl(): string
    {
        $setting = PlatformSetting::get('payment.obpay.callback_url');
        if (is_string($setting) && trim($setting) !== '') {
            return trim($setting);
        }

        $url = trim((string) config('services.obpay.callback_url', ''));

        return $url !== ''
            ? $url
            : rtrim((string) config('app.url', 'http://localhost'), '/').route('api.webhooks.obpay', [], false);
    }

    public static function obpayMissingRequirements(): array
    {
        $missing = [];
        if (self::obpaySecretKey() === null) {
            $missing[] = 'API secret key (Payments UI or OBPAY_SECRET_KEY)';
        }
        if (self::obpayWebhookSecret() === null) {
            $missing[] = 'webhook signing secret (Payments UI or OBPAY_WEBHOOK_SECRET)';
        }
        if (! self::obpayBaseUrlIsSecure()) {
            $missing[] = 'valid HTTPS API base URL (OBPAY_BASE_URL)';
        }
        if (! app()->environment('testing') && ! self::isHttpsUrl(self::obpayCallbackUrl())) {
            $missing[] = 'public HTTPS callback URL (Payments UI, OBPAY_CALLBACK_URL, or HTTPS APP_URL)';
        }

        return $missing;
    }

    public static function missingRequirements(string $provider): array
    {
        return match ($provider) {
            'obpay' => self::obpayMissingRequirements(),
            'pawapay' => filled(PlatformSetting::getSecret('payment.pawapay.api_token'))
                ? []
                : ['API token (Payments UI)'],
            'iotec' => array_values(array_filter([
                filled(PlatformSetting::getSecret('payment.iotec.client_id')) ? null : 'OAuth client ID (Payments UI)',
                filled(PlatformSetting::getSecret('payment.iotec.client_secret')) ? null : 'OAuth client secret (Payments UI)',
                filled(PlatformSetting::get('payment.iotec.wallet_id')) ? null : 'wallet ID (Payments UI)',
                filled(PlatformSetting::getSecret('payment.iotec.webhook_secret')) ? null : 'callback bearer secret (Payments UI)',
            ])),
            default => ['supported payment gateway'],
        };
    }

    public static function enabledProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, self::configured(...)));
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            'obpay' => 'OBPay',
            'pawapay' => 'PawaPay',
            'iotec' => 'ioTec',
            default => 'Unknown provider',
        };
    }

    public static function activeLabel(): string
    {
        $active = self::active();

        return $active && self::configured($active)
            ? self::label($active)
            : 'No payment gateway enabled';
    }

    private static function obpaySecret(string $settingKey, string $configKey): ?string
    {
        $value = PlatformSetting::getSecret($settingKey);
        if (! filled($value)) {
            $value = config($configKey);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function obpayBaseUrlIsSecure(): bool
    {
        try {
            self::obpayBaseUrl();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
