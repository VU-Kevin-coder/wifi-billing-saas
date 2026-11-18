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
            'obpay' => filled(PlatformSetting::getSecret('payment.obpay.secret_key'))
                && filled(PlatformSetting::getSecret('payment.obpay.webhook_secret')),
            'pawapay' => filled(PlatformSetting::getSecret('payment.pawapay.api_token')),
            'iotec' => filled(PlatformSetting::getSecret('payment.iotec.client_id'))
                && filled(PlatformSetting::getSecret('payment.iotec.client_secret'))
                && filled(PlatformSetting::get('payment.iotec.wallet_id'))
                && filled(PlatformSetting::getSecret('payment.iotec.webhook_secret')),
            default => false,
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
}
