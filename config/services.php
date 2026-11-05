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

];
