<?php

// Validate before loading tests and again before Laravel creates a connection.
// Fixed endpoints and opt-in do not prove that a service is disposable.
$selectedDriver = $_ENV['TEST_DB_DRIVER'] ?? getenv('TEST_DB_DRIVER') ?: null;
$driver = $selectedDriver ?? 'sqlite';
$expected = $_ENV['TEST_EXPECTED_DB_DRIVER'] ?? getenv('TEST_EXPECTED_DB_DRIVER') ?: null;
$required = $_ENV['TEST_DB_REQUIRE_EXPLICIT'] ?? getenv('TEST_DB_REQUIRE_EXPLICIT') ?: null;
if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)
    || ($required === '1' && ($selectedDriver === null || $expected === null))
    || ($expected !== null && $expected !== $driver)
    || ($driver !== 'sqlite' && ($_ENV['TEST_DB_DISPOSABLE'] ?? getenv('TEST_DB_DISPOSABLE')) !== 'yes')) {
    throw new RuntimeException('Unsafe or inconsistent test database selection; refusing to run.');
}

return $driver;
