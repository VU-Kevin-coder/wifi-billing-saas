<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Base Domain
    |--------------------------------------------------------------------------
    |
    | Tenant workspaces are served from subdomains of this host. In local
    | development this is usually "localhost"; in production set it to the
    | root domain (for example "wifi.example.com").
    |
    */

    'base_domain' => env('TENANT_BASE_DOMAIN')
        ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | These slugs cannot be registered as tenant subdomains because they are
    | used by the platform itself or commonly reserved by infrastructure.
    |
    */

    'reserved_slugs' => [
        'admin',
        'api',
        'app',
        'assets',
        'dashboard',
        'ftp',
        'login',
        'mail',
        'operator',
        'platform',
        'portal',
        'register',
        'smtp',
        'sso',
        'static',
        'storage',
        'support',
        'www',
        'webhooks',
    ],

];
