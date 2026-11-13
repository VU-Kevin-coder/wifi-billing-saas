<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
        'environment' => env('FLUTTERWAVE_ENVIRONMENT', 'sandbox'),
    ],

    'mtn_momo' => [
        'sandbox_url' => env('MTN_MOMO_SANDBOX_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'production_url' => env('MTN_MOMO_PRODUCTION_URL', 'https://proxy.momoapi.mtn.com'),
        'webhook_url' => env('MTN_MOMO_WEBHOOK_URL'),
    ],

    'airtel_money' => [
        'sandbox_url' => env('AIRTEL_MONEY_SANDBOX_URL', 'https://openapiuat.airtel.africa'),
        'production_url' => env('AIRTEL_MONEY_PRODUCTION_URL', 'https://openapi.airtel.africa'),
        'webhook_url' => env('AIRTEL_MONEY_WEBHOOK_URL'),
    ],

    'mikrotik' => [
        'api_port' => (int) env('MIKROTIK_DEFAULT_API_PORT', 8728),
        'api_username' => env('MIKROTIK_DEFAULT_API_USERNAME', 'billing_api'),
        'hotspot_domain' => env('MIKROTIK_HOTSPOT_DOMAIN', 'hotspot.local'),
        'hotspot_network' => env('MIKROTIK_DEFAULT_HOTSPOT_NETWORK', '10.10.0.0/16'),
        'gateway' => env('MIKROTIK_DEFAULT_GATEWAY', '10.10.0.1'),
        'dns_servers' => env('MIKROTIK_DEFAULT_DNS_SERVERS', '8.8.8.8,8.8.4.4'),
        'client_ports' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MIKROTIK_DEFAULT_CLIENT_PORTS', 'ether2,ether3'))
        ))),
    ],

];
