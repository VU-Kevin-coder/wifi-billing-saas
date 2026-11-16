<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Application-level safeguards for a dedicated payment lab; not an egress firewall. */
class PaymentSandboxGuard
{
    public static function enabled(): bool
    {
        // A staging deployment must not silently opt out. Dedicated labs using
        // another APP_ENV must explicitly set PAYMENT_LAB_MODE=true.
        return app()->environment('staging')
            || (bool) config('payments.lab_mode', false)
            || (bool) config('payments.sandbox_only', false);
    }

    public static function http(): PendingRequest
    {
        return Http::withOptions(
            self::enabled() ? ['allow_redirects' => false] : []
        );
    }

    public static function requireSuccess(Response $response): Response
    {
        if (! $response->successful()) {
            throw new RuntimeException('Payment provider request returned an unsuccessful response.');
        }

        return $response;
    }

    public static function blockUnrelated(string $operation): void
    {
        if (self::enabled()) {
            throw new RuntimeException($operation.' is disabled in the payment sandbox lab.');
        }
    }

    public static function check(string $provider, string $url): void
    {
        if (! self::enabled()) {
            return;
        }

        $settings = [
            'mtn' => ['mtn_environment', 'sandbox.momodeveloper.mtn.com'],
            'airtel' => ['airtel_environment', 'openapiuat.airtel.africa'],
            'pesapal' => ['pesapal_environment', 'cybqa.pesapal.com'],
        ];
        if (! isset($settings[$provider])) {
            throw new RuntimeException('Unknown sandbox payment provider.');
        }
        [$setting, $host] = $settings[$provider];
        $parts = parse_url($url);
        if (PlatformSetting::get($setting, 'sandbox') !== 'sandbox'
            || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== $host
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('Sandbox-only payment guard rejected the provider configuration.');
        }
    }
}
