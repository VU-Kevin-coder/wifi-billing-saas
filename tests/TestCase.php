<?php

namespace Tests;

use App\Services\Policy\Policy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $environmentPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wifi-billing-phpunit-'.bin2hex(random_bytes(16));
        $testEnvironment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'APP_CONFIG_CACHE' => $environmentPath.DIRECTORY_SEPARATOR.'config-cache.php',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'WIREGUARD_ENABLED' => 'false',
        ];

        $environmentValue = static function (string $name): string {
            $value = getenv($name);
            if ($value === false) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
            }

            return is_scalar($value) ? (string) $value : '';
        };

        if (filter_var($environmentValue('PHPUNIT_USE_CONFIGURED_DATABASE'), FILTER_VALIDATE_BOOLEAN)) {
            $connection = $environmentValue('DB_CONNECTION');
            $host = $environmentValue('DB_HOST');
            $database = $environmentValue('DB_DATABASE');

            if (! in_array($connection, ['mysql', 'pgsql'], true)
                || ! in_array($host, ['127.0.0.1', 'localhost'], true)
                || $database !== 'testing') {
                throw new RuntimeException('Configured-database tests require a local MySQL/PostgreSQL test service and the testing database.');
            }

            $testEnvironment = array_merge($testEnvironment, [
                'DB_CONNECTION' => $connection,
                'DB_HOST' => $host,
                'DB_PORT' => $environmentValue('DB_PORT'),
                'DB_DATABASE' => $database,
                'DB_USERNAME' => $environmentValue('DB_USERNAME'),
                'DB_PASSWORD' => $environmentValue('DB_PASSWORD'),
                'DB_URL' => '',
            ]);
        } else {
            $testEnvironment = array_merge($testEnvironment, [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'DB_FOREIGN_KEYS' => 'true',
            ]);
        }

        foreach ($testEnvironment as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $app->useEnvironmentPath($environmentPath);
        $app->loadEnvironmentFrom('phpunit-environment-disabled');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->make(Kernel::class)->bootstrap();

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
