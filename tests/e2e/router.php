<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__, 2);
$publicPath = realpath($root.DIRECTORY_SEPARATOR.'public');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($uriPath === '/__playwright/ready') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ready';

    return true;
}

$candidate = $publicPath === false
    ? false
    : realpath($publicPath.DIRECTORY_SEPARATOR.ltrim(rawurldecode($uriPath), '/\\'));

if ($candidate !== false && $publicPath !== false
    && is_file($candidate)
    && str_starts_with(strtolower($candidate), strtolower($publicPath.DIRECTORY_SEPARATOR))) {
    return false;
}

$environmentPath = getenv('PLAYWRIGHT_ENV_PATH') ?: '';
$temporaryRoot = realpath(sys_get_temp_dir());

if ($temporaryRoot === false || realpath($environmentPath) === false
    || ! str_starts_with(realpath($environmentPath), $temporaryRoot.DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename($environmentPath), 'wifi-billing-playwright-')
    || getenv('DB_CONNECTION') !== 'sqlite'
    || (getenv('DB_URL') ?: '') !== '') {
    http_response_code(500);
    echo 'Isolated Playwright test configuration is required.';

    return true;
}

require $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$app = require $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->useEnvironmentPath($environmentPath);
$app->loadEnvironmentFrom('playwright-environment-disabled');

$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();
config(['session.files' => getenv('PLAYWRIGHT_SESSION_PATH')]);

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
