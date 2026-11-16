<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class LibpqEnvironmentSafetyTest extends TestCase
{
    public function test_libpq_hostaddr_cannot_redirect_after_test_environment_is_sanitized(): void
    {
        if (! in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_pgsql is required for the libpq network regression.');
        }

        // Both listeners are inert TCP sockets on loopback, not database servers.
        // No SQL, migrations, or application bootstrap are run by this probe.
        $local = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($local, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($local, false), ':'), 1);
        $redirect = stream_socket_server('tcp://127.0.0.2:'.$port, $errno, $error);
        $this->assertNotFalse($redirect, $error);
        $serviceFile = tempnam(sys_get_temp_dir(), 'libpq-service-');
        $this->assertNotFalse($serviceFile);
        file_put_contents($serviceFile, "[hostile]\nhostaddr=127.0.0.2\n");

        try {
            // Positive control proves this libpq actually honors hostile PGHOSTADDR.
            $this->assertSame('redirect', $this->connectionDestination($local, $redirect, $port, false, $serviceFile));
            // With the same harness sanitizer used before Laravel bootstrap, even
            // hostaddr, service files, options and arbitrary future PG* keys vanish.
            $this->assertSame('local', $this->connectionDestination($local, $redirect, $port, true, $serviceFile));
        } finally {
            fclose($local);
            fclose($redirect);
            unlink($serviceFile);
        }
    }

    private function connectionDestination($local, $redirect, int $port, bool $sanitize, string $serviceFile): string
    {
        $code = <<<'PHP'
            if ($argv[1] === 'yes') {
                $_ENV['PGFUTURE_TEST'] = 'env';
                $_SERVER['PGSERVER_TEST'] = 'server';
                require $argv[2];
                foreach (array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER)) as $key) {
                    if (is_string($key) && str_starts_with($key, 'PG')) { exit(9); }
                }
            }
            try {
                new PDO('pgsql:host=127.0.0.1;port='.$argv[3].';dbname=probe;connect_timeout=2', 'probe', 'probe');
                exit(8); // An inert listener must never authenticate or execute SQL.
            } catch (PDOException $e) {
                echo 'expected connection failure';
            }
            PHP;
        $env = [];
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'PG')) {
                $env[$key] = false;
            }
        }
        $env['PGHOSTADDR'] = '127.0.0.2';
        $env['PGSSLMODE'] = 'disable';
        if ($sanitize) {
            $env['PGSERVICE'] = 'hostile';
            $env['PGSERVICEFILE'] = $serviceFile;
            $env['PGOPTIONS'] = '-c statement_timeout=1';
            $env['PGPASSFILE'] = '/nonexistent/libpq-password';
        }
        $process = new Process([
            PHP_BINARY, '-r', $code, $sanitize ? 'yes' : 'no',
            dirname(__DIR__).'/sanitize-libpq-environment.php', (string) $port,
        ], dirname(__DIR__, 2), $env, null, 10);
        $process->start();
        $read = [$local, $redirect];
        $write = $except = [];
        $ready = stream_select($read, $write, $except, 5);
        $destination = 'none';
        if ($ready > 0) {
            $read = array_values($read);
            $destination = $read[0] === $local ? 'local' : 'redirect';
            $connection = stream_socket_accept($read[0], 0);
            if ($connection !== false) {
                fclose($connection);
            }
        }
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('expected connection failure', $process->getOutput());

        return $destination;
    }
}
