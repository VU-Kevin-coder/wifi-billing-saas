<?php

return [
    'enabled' => filter_var(env('WIREGUARD_ENABLED', false), FILTER_VALIDATE_BOOL),
    'interface' => env('WIREGUARD_INTERFACE', 'wg0'),
    'server_address' => env('WIREGUARD_SERVER_ADDRESS', '10.8.0.1'),
    'endpoint' => env('WIREGUARD_ENDPOINT'),
    'listen_port' => (int) env('WIREGUARD_LISTEN_PORT', 51820),
    'keepalive' => (int) env('WIREGUARD_KEEPALIVE', 25),
    'router_interface' => 'wg-billing',
];
