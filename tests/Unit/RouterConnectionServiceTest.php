<?php

use App\Models\Router;
use App\Services\RouterConnectionService;

it('refuses a connection test without complete router credentials', function () {
    $router = new Router([
        'management_ip' => '192.168.88.1',
        'api_username' => 'demo-api',
        // No password: should not attempt the network connection.
    ]);

    $result = app(RouterConnectionService::class)->test($router);

    expect($result)
        ->toMatchArray(['ok' => false])
        ->and($result['message'])->toContain('Add a host/IP');
});
