<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;

final class PaymentGatewaySettings
{
    public const PROVIDERS = ['obpay', 'pawapay', 'xente'];

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
            'xente' => filled(PlatformSetting::getSecret('payment.xente.app_key'))
                && filled(PlatformSetting::getSecret('payment.xente.app_password'))
                && filled(PlatformSetting::getSecret('payment.xente.user_id'))
                && collect(['mtn', 'airtel'])->every(
                    fn (string $network): bool => filled(PlatformSetting::get("payment.xente.collection_{$network}"))
                        && filled(PlatformSetting::get("payment.xente.payout_{$network}"))
                ),
            default => false,
        };
    }

    public static function enabledProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, self::configured(...)));
    }

    public static function activeLabel(): string
    {
        return match (self::active()) {
            'obpay' => 'ObPay',
            'pawapay' => 'PawaPay',
            'xente' => 'Xente',
            default => 'No payment gateway enabled',
        };
    }
}
