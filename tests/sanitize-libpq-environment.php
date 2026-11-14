<?php

// This is test-harness code, not application configuration. libpq can use many
// PG* variables (notably PGHOSTADDR and PGSERVICE) even with an explicit DSN.
// Clear the entire namespace, including future libpq additions, before Laravel
// boots or anything can obtain a PDO connection.
foreach (array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))) as $name) {
    if (is_string($name) && str_starts_with($name, 'PG')) {
        if (! putenv($name)) {
            throw new RuntimeException('Could not clear libpq test environment; refusing to run.');
        }
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
