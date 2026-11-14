<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_ci_rejects_mismatched_expected_driver_before_connecting(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => 'pgsql',
            'TEST_EXPECTED_DB_DRIVER' => 'mysql',
            'TEST_DB_DISPOSABLE' => 'yes',
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput());
    }

    public function test_ci_rejects_server_database_without_disposable_opt_in(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => 'mysql',
            'TEST_EXPECTED_DB_DRIVER' => 'mysql',
            'TEST_DB_DISPOSABLE' => false,
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput());
    }

    public function test_ci_requires_explicit_expectation(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => false,
            'TEST_EXPECTED_DB_DRIVER' => false,
            'TEST_DB_DISPOSABLE' => false,
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput());
    }

    public function test_default_sqlite_ignores_ambient_application_database_settings(): void
    {
        $process = $this->runProbe('phpunit.xml', [
            'TEST_DB_DRIVER' => 'mysql',
            'TEST_EXPECTED_DB_DRIVER' => 'mysql',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'production',
            'DB_HOST' => 'production.invalid',
            'DB_URL' => 'mysql://user:password@production.invalid/production',
            'DB_SOCKET' => '/tmp/production.sock',
        ]);
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (1 test', $process->getOutput());
    }

    private function runProbe(string $configuration, array $environment): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/phpunit', '-c', $root.'/'.$configuration,
            $root.'/tests/Feature/TestDatabaseConnectionTest.php', '--no-coverage',
        ], $root, $environment, null, 30);
        $process->run();

        return $process;
    }
}
