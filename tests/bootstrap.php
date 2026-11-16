<?php

// PHPUnit applies XML environment settings before loading this bootstrap.
require __DIR__.'/sanitize-libpq-environment.php';
require __DIR__.'/validate-test-database-selection.php';

require dirname(__DIR__).'/vendor/autoload.php';
