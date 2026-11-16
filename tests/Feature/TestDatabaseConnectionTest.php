<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class TestDatabaseConnectionTest extends TestCase
{
    public function test_live_database_matches_the_explicit_job_expectation(): void
    {
        $names = array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER)));
        $this->assertEmpty(array_filter($names, fn ($name) => is_string($name) && str_starts_with($name, 'PG')), 'Ambient libpq variable survived test bootstrap.');
        $expected = $_ENV['TEST_EXPECTED_DB_DRIVER'] ?? getenv('TEST_EXPECTED_DB_DRIVER') ?: 'sqlite';
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->assertSame($expected, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertSame($expected, $connection->getDriverName());
        if ($expected === 'sqlite') {
            $this->assertSame(':memory:', $connection->getDatabaseName());
            $this->assertSame('', $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)[0]['file']);
        } else {
            $sql = $expected === 'mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()';
            $this->assertSame('wifi_billing_test', $pdo->query($sql)->fetchColumn());
            $this->assertSame('127.0.0.1', $connection->getConfig('host'));
            $this->assertSame('wifi_test_runner', $connection->getConfig('username'));
        }
    }
}
