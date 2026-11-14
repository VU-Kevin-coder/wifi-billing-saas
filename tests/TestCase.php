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
        $testEnvironment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'APP_CONFIG_CACHE' => $environmentPath.DIRECTORY_SEPARATOR.'config-cache.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
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
