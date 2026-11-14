<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Cookie\CookieValuePrefix;

$root = dirname(__DIR__, 2);
$testingRoot = realpath($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing');
$databasePath = getenv('DB_DATABASE') ?: '';
$sessionPath = getenv('PLAYWRIGHT_SESSION_PATH') ?: '';
$environmentPath = getenv('PLAYWRIGHT_ENV_PATH') ?: '';
$fixturePath = getenv('PLAYWRIGHT_FIXTURE_PATH') ?: '';

if (getenv('DB_CONNECTION') !== 'sqlite' || (getenv('DB_URL') ?: '') !== '') {
    throw new RuntimeException('Playwright setup requires an explicit SQLite database and no database URL.');
}

if ($testingRoot === false || realpath(dirname($databasePath)) !== $testingRoot
    || basename($databasePath) !== 'phase1-playwright-local.sqlite') {
    throw new RuntimeException('Refusing to use a database outside the dedicated Playwright SQLite test directory.');
}

if ($testingRoot === false || realpath(dirname($sessionPath)) !== $testingRoot
    || basename($sessionPath) !== 'phase1-playwright-sessions-local') {
    throw new RuntimeException('Refusing to use a session directory outside the dedicated Playwright test directory.');
}

$temporaryRoot = realpath(sys_get_temp_dir());
if ($temporaryRoot === false || realpath($environmentPath) === false
    || ! str_starts_with(realpath($environmentPath), $temporaryRoot.DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename($environmentPath), 'wifi-billing-playwright-')) {
    throw new RuntimeException('Playwright must load environment configuration from its isolated empty temporary directory.');
}

if (! str_starts_with($fixturePath, $testingRoot.DIRECTORY_SEPARATOR)
    || basename($fixturePath) !== 'phase1-playwright-fixture-local.json') {
    throw new RuntimeException('Refusing to write a Playwright fixture outside the dedicated test directory.');
}

if (! is_dir($sessionPath) && ! mkdir($sessionPath, 0700, true) && ! is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create the isolated Playwright session directory.');
}

if (file_exists($databasePath) && ! unlink($databasePath)) {
    throw new RuntimeException('Unable to reset the dedicated Playwright SQLite database.');
}

require $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$app = require $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->useEnvironmentPath($environmentPath);
$app->loadEnvironmentFrom('playwright-environment-disabled');

$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

config(['session.files' => $sessionPath]);

$exitCode = $kernel->call('migrate:fresh', [
    '--database' => 'sqlite',
    '--force' => true,
]);

if ($exitCode !== 0) {
    throw new RuntimeException('The isolated Playwright SQLite migrations did not complete successfully.');
}

$tenant = Tenant::create([
    'name' => 'Phase 1 E2E Wi-Fi',
    'slug' => 'phase1-e2e',
    'status' => 'active',
]);

$package = Package::create([
    'tenant_id' => $tenant->id,
    'name' => 'Daily Pass',
    'price' => 2000,
    'currency' => 'UGX',
    'duration_value' => 1,
    'duration_unit' => 'days',
    'speed_limit_mbps' => 5,
    'device_limit' => 1,
    'is_published' => true,
    'data_limit' => '5GB',
]);

$paymentToken = bin2hex(random_bytes(32));

Transaction::create([
    'tenant_id' => $tenant->id,
    'type' => 'voucher_sale',
    'amount' => $package->price,
    'status' => 'pending',
    'reference' => 'ONL-PLAYWRIGHT',
    'payment_token_hash' => hash('sha256', $paymentToken),
    'phone' => '0771234567',
    'description' => 'Playwright pending payment fixture',
    'meta' => [
        'package_id' => $package->id,
        'phone' => '0771234567',
    ],
]);

$sessionId = bin2hex(random_bytes(20));
$session = app('session')->driver();
$session->setId($sessionId);
$session->start();
$session->put('payment_token', $paymentToken);
$session->save();

$cookieName = (string) config('session.cookie');
$cookieValue = CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$sessionId;
$encryptedCookie = app('encrypter')->encrypt($cookieValue, false);

if (file_put_contents($fixturePath, json_encode(['sessionCookie' => $encryptedCookie], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Unable to write the Playwright browser session fixture.');
}
