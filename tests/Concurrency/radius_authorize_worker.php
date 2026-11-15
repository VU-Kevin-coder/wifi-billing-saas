<?php

use App\Services\RadiusAccountingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$repositoryRoot = dirname(__DIR__, 2);
$connection = getenv('DB_CONNECTION');
$host = getenv('DB_HOST');
$database = getenv('DB_DATABASE');

if (! in_array($connection, ['mysql', 'pgsql'], true)
    || ! in_array($host, ['127.0.0.1', 'localhost'], true)
    || $database !== 'testing'
    || $argc !== 6) {
    fwrite(STDERR, "Worker requires a local MySQL/PostgreSQL testing database.\n");
    exit(2);
}

$nasIdentifier = $argv[1];
$nasSessionId = $argv[2];
$voucherCode = $argv[3];
$readyPath = $argv[4];
$releasePath = $argv[5];
$environmentPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wifi-billing-radius-worker-'.bin2hex(random_bytes(16));
if (! mkdir($environmentPath, 0700, true) && ! is_dir($environmentPath)) {
    fwrite(STDERR, "Worker environment could not be initialized.\n");
    exit(2);
}

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
    'APP_CONFIG_CACHE' => $environmentPath.DIRECTORY_SEPARATOR.'config-cache.php',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'WIREGUARD_ENABLED' => 'false',
    'PHPUNIT_USE_CONFIGURED_DATABASE' => 'true',
    'DB_CONNECTION' => $connection,
    'DB_HOST' => $host,
    'DB_PORT' => getenv('DB_PORT') ?: '',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => getenv('DB_USERNAME') ?: '',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    'DB_URL' => '',
];

foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$app = null;
$exitCode = 0;
try {
    require $repositoryRoot.'/vendor/autoload.php';
    $app = require $repositoryRoot.'/bootstrap/app.php';
    $app->useEnvironmentPath($environmentPath);
    $app->loadEnvironmentFrom('phpunit-environment-disabled');
    $app->make(Kernel::class)->bootstrap();

    $configured = config('database.connections.'.config('database.default'));
    if (config('database.default') !== $connection
        || ! in_array($configured['host'] ?? null, ['127.0.0.1', 'localhost'], true)
        || ($configured['database'] ?? null) !== 'testing') {
        throw new RuntimeException('Worker database configuration failed its local test-target check.');
    }

    if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Worker could not join the concurrency barrier.');
    }

    $deadline = microtime(true) + 35;
    while (! file_exists($releasePath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($releasePath)) {
        throw new RuntimeException('Worker concurrency barrier timed out.');
    }

    $decision = app(RadiusAccountingService::class)->authorize(
        $nasIdentifier,
        $nasSessionId,
        $voucherCode,
    );
    echo json_encode(['decision' => $decision['decision']], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Authorization worker failed.\n");
    $exitCode = 1;
} finally {
    if ($app) {
        try {
            DB::disconnect();
        } catch (Throwable) {
            // Cleanup must not mask a worker result or expose connection details.
        }
    }
    $configCache = $environmentPath.DIRECTORY_SEPARATOR.'config-cache.php';
    if (is_file($configCache)) {
        @unlink($configCache);
    }
    @rmdir($environmentPath);
}

exit($exitCode);
