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
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput().$process->getErrorOutput());
    }

    public function test_ci_rejects_server_database_without_disposable_opt_in(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => 'mysql',
            'TEST_EXPECTED_DB_DRIVER' => 'mysql',
            'TEST_DB_DISPOSABLE' => false,
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput().$process->getErrorOutput());
    }

    public function test_ci_requires_explicit_expectation(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => 'sqlite',
            'TEST_EXPECTED_DB_DRIVER' => false,
            'TEST_DB_DISPOSABLE' => false,
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput().$process->getErrorOutput());
    }

    public function test_ci_requires_explicit_driver_even_when_expected_is_sqlite(): void
    {
        $process = $this->runProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => false,
            'TEST_EXPECTED_DB_DRIVER' => 'sqlite',
            'TEST_DB_DISPOSABLE' => false,
        ]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Unsafe or inconsistent test database selection', $process->getOutput().$process->getErrorOutput());
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
            'PGHOSTADDR' => '127.0.0.2',
            'PGSERVICE' => 'hostile-service',
            'PGSERVICEFILE' => '/tmp/hostile-service.conf',
            'PGOPTIONS' => '-c statement_timeout=1',
        ]);
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (1 test', $process->getOutput());
    }

    public function test_standalone_unit_bootstrap_rejects_missing_and_mismatched_markers(): void
    {
        foreach ([
            ['TEST_DB_DRIVER' => false, 'TEST_EXPECTED_DB_DRIVER' => false],
            ['TEST_DB_DRIVER' => 'sqlite', 'TEST_EXPECTED_DB_DRIVER' => false],
            ['TEST_DB_DRIVER' => false, 'TEST_EXPECTED_DB_DRIVER' => 'sqlite'],
            ['TEST_DB_DRIVER' => 'pgsql', 'TEST_EXPECTED_DB_DRIVER' => 'mysql'],
            ['TEST_DB_DRIVER' => 'mysql', 'TEST_EXPECTED_DB_DRIVER' => 'mysql', 'TEST_DB_DISPOSABLE' => false],
        ] as $case) {
            $process = $this->runStandaloneProbe('phpunit.ci-db.xml', $case);
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertFalse($process->isSuccessful(), $output);
            $this->assertStringContainsString('Unsafe or inconsistent test database selection', $output);
            $this->assertStringNotContainsString('OK (1 test', $output);
        }
    }

    public function test_standalone_unit_bootstrap_accepts_valid_markers_and_sanitizes_libpq(): void
    {
        $process = $this->runStandaloneProbe('phpunit.ci-db.xml', [
            'TEST_DB_DRIVER' => 'sqlite',
            'TEST_EXPECTED_DB_DRIVER' => 'sqlite',
            'PGHOSTADDR' => '127.0.0.2',
            'PGSERVICE' => 'hostile',
            'PGSERVICEFILE' => '/tmp/hostile',
            'PGOPTIONS' => '-c statement_timeout=1',
        ]);
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertStringContainsString('OK (1 test', $output);
    }

    public function test_default_standalone_unit_bootstrap_ignores_hostile_markers(): void
    {
        $process = $this->runStandaloneProbe('phpunit.xml', [
            'TEST_DB_DRIVER' => 'mysql',
            'TEST_EXPECTED_DB_DRIVER' => 'pgsql',
            'PGHOSTADDR' => '127.0.0.2',
        ]);
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertStringContainsString('OK (1 test', $output);
    }

    private function runStandaloneProbe(string $configuration, array $environment): Process
    {
        $root = dirname(__DIR__, 2);
        $environment = array_merge([
            'TEST_DB_DRIVER' => false,
            'TEST_EXPECTED_DB_DRIVER' => false,
            'TEST_DB_DISPOSABLE' => 'yes',
        ], $environment);
        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/phpunit', '-c', $root.'/'.$configuration,
            $root.'/tests/fixtures/StandaloneBootstrapProbeTest.php', '--no-coverage',
        ], $root, $environment, null, 30);
        $process->run();

        return $process;
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
