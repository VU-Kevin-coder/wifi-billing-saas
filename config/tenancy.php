<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant URL Mode
    |--------------------------------------------------------------------------
    |
    | Use "path" when the hosting provider supplies one shared domain without
    | wildcard subdomains. Tenant workspaces then live at /t/{slug}.
    |
    */

    'url_mode' => env('TENANT_URL_MODE', 'subdomain') === 'path' ? 'path' : 'subdomain',

    /*
    |--------------------------------------------------------------------------
    | Tenant Base Domain
    |--------------------------------------------------------------------------
    |
    | Used by subdomain mode. In local development this is usually "localhost";
    | in production set it to the root domain (for example "wifi.example.com").
    |
    */

    'base_domain' => env('TENANT_BASE_DOMAIN')
        ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Tenant Slugs
    |--------------------------------------------------------------------------
    |
    | These slugs cannot be registered as tenant workspaces because they are
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
