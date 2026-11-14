<?php

use PHPUnit\Framework\TestCase;

// This intentionally never extends Tests\TestCase or boots Laravel.
final class StandaloneBootstrapProbeTest extends TestCase
{
    public function test_bootstrap_cleared_libpq_environment(): void
    {
        foreach (array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER)) as $key) {
            $this->assertFalse(is_string($key) && str_starts_with($key, 'PG'), 'Ambient PG variable remains');
        }
        $this->assertTrue(true);
    }
}
