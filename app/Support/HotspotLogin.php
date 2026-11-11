<?php

namespace App\Support;

use App\Models\Router;
use App\Models\Session;
use App\Models\Voucher;

/**
 * Builds the MikroTik hotspot login link for a voucher from the gateway of
 * the router the customer is actually connected to. (It used to be one
 * hardcoded address for every tenant and site.)
 */
class HotspotLogin
{
    public static function forVoucher(Voucher $voucher): ?string
    {
        $session = Session::withoutTenancy()
            ->where('tenant_id', $voucher->tenant_id)
            ->where('voucher_id', $voucher->id)
            ->latest('id')
            ->first();

        $router = $session?->router_id
            ? Router::withoutTenancy()->where('tenant_id', $voucher->tenant_id)->find($session->router_id)
            : null;

        return self::url($router);
    }

    public static function url(?Router $router): ?string
    {
        $gateway = self::validGateway($router?->hotspot_config['gateway'] ?? null)
            ?? self::validGateway(config('hotspot.default_gateway'));

        return $gateway ? 'http://'.$gateway.'/login' : null;
    }

    /**
     * Only a plain IPv4 address is accepted: the customer's browser is sent
     * there, so a hostname or URL from a form field must never be trusted.
     */
    public static function validGateway(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $value : null;
    }
}
