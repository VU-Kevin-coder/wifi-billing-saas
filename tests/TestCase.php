<?php

namespace Tests;

use App\Services\Policy\Policy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $environmentPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wifi-billing-phpunit-'.bin2hex(random_bytes(16));
        // Server databases are opt-in and deliberately restricted to dedicated,
        // disposable loopback services. Never inherit application DB_* settings.
        $driver = $_ENV['TEST_DB_DRIVER'] ?? getenv('TEST_DB_DRIVER') ?: 'sqlite';
        $expected = $_ENV['TEST_EXPECTED_DB_DRIVER'] ?? getenv('TEST_EXPECTED_DB_DRIVER') ?: null;
        $required = $_ENV['TEST_DB_REQUIRE_EXPLICIT'] ?? getenv('TEST_DB_REQUIRE_EXPLICIT') ?: null;
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)
            || ($required === '1' && $expected === null)
            || ($expected !== null && $expected !== $driver)
            || ($driver !== 'sqlite' && ($_ENV['TEST_DB_DISPOSABLE'] ?? getenv('TEST_DB_DISPOSABLE')) !== 'yes')) {
            throw new \RuntimeException('Unsafe or inconsistent test database selection; refusing to run.');
        }

        $testEnvironment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'APP_CONFIG_CACHE' => $environmentPath.DIRECTORY_SEPARATOR.'config-cache.php',
            'DB_CONNECTION' => $driver,
            'DB_DATABASE' => $driver === 'sqlite' ? ':memory:' : 'wifi_billing_test',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $driver === 'mysql' ? '13306' : '15432',
            'DB_USERNAME' => 'wifi_test_runner',
            'DB_PASSWORD' => 'disposable-test-password',
            'DB_SOCKET' => '',
            'DB_URL' => '',
            'DB_FOREIGN_KEYS' => 'true',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'WIREGUARD_ENABLED' => 'false',
        ];

        foreach ($testEnvironment as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $app->useEnvironmentPath($environmentPath);
        $app->loadEnvironmentFrom('phpunit-environment-disabled');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->make(Kernel::class)->bootstrap();

        // Do this before RefreshDatabase can migrate or wipe anything. Check the
        // live PDO connection, not merely Laravel's configured connection name.
        $connection = $app->make('db')->connection();
        $pdo = $connection->getPdo();
        $actualDriver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $actualDatabase = match ($driver) {
            'mysql' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'pgsql' => $pdo->query('SELECT current_database()')->fetchColumn(),
            'sqlite' => $pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC)[0]['file'] ?? null,
        };
        if ($actualDriver !== $driver || $actualDatabase !== ($driver === 'sqlite' ? '' : 'wifi_billing_test')) {
            throw new \RuntimeException('Connected to an unexpected test database; refusing to run migrations.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Policy::flush();

        // Pages call @vite(); without this, every page test would need a built
        // public/build/manifest.json (and fail on a clean CI checkout).
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Policy::flush();
        parent::tearDown();
    }

    protected function idempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
