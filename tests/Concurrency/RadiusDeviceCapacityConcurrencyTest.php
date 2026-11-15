<?php

namespace Tests\Concurrency;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\RadiusRouterCredentialService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RadiusDeviceCapacityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Fail before DatabaseMigrations can touch any database unless this test
     * was explicitly run with the dedicated local CI database configuration.
     */
    public function createApplication(): Application
    {
        $environmentValue = static function (string $name): string {
            $value = getenv($name);
            if ($value === false) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
            }

            return is_scalar($value) ? (string) $value : '';
        };
        $connection = $environmentValue('DB_CONNECTION');
        $host = $environmentValue('DB_HOST');
        $database = $environmentValue('DB_DATABASE');
        $configuredDatabase = filter_var(
            $environmentValue('PHPUNIT_USE_CONFIGURED_DATABASE'),
            FILTER_VALIDATE_BOOLEAN
        );
        if (! $configuredDatabase
            || ! in_array($connection, ['mysql', 'pgsql'], true)
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || $database !== 'testing') {
            throw new RuntimeException("RADIUS concurrency tests require phpunit.ci-db.xml and a local MySQL/PostgreSQL testing database (configured={$configuredDatabase}, connection={$connection}, host={$host}, database={$database}).");
        }

        return parent::createApplication();
    }

    public function test_two_simultaneous_nas_authorizations_cannot_overbook_device_limit_one(): void
    {
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertContains(DB::connection()->getDriverName(), ['mysql', 'pgsql']);
        $this->assertSame(0, DB::transactionLevel(), 'Concurrency fixture must be committed outside an outer test transaction.');

        [$router, $voucher] = $this->createOneDeviceEntitlement();
        $this->assertSame(0, DB::transactionLevel(), 'The shared entitlement fixture must be committed before worker startup.');

        $barrierDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wifi-billing-radius-concurrency-'.bin2hex(random_bytes(12));
        if (! mkdir($barrierDirectory, 0700, true) && ! is_dir($barrierDirectory)) {
            $this->fail('Could not create the local concurrency-test barrier directory.');
        }

        $readyPaths = [
            $barrierDirectory.DIRECTORY_SEPARATOR.'worker-one.ready',
            $barrierDirectory.DIRECTORY_SEPARATOR.'worker-two.ready',
        ];
        $releasePath = $barrierDirectory.DIRECTORY_SEPARATOR.'release';
        $workerPath = __DIR__.DIRECTORY_SEPARATOR.'radius_authorize_worker.php';
        $nasSessionIds = ['parallel-device-one', 'parallel-device-two'];
        $processes = [];

        try {
            foreach ($nasSessionIds as $index => $nasSessionId) {
                $process = new Process([
                    PHP_BINARY,
                    $workerPath,
                    $router->radius_nas_identifier,
                    $nasSessionId,
                    $voucher->code,
                    $readyPaths[$index],
                    $releasePath,
                ], dirname(__DIR__, 2), null);
                $process->setTimeout(45);
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 25;
            while ((! file_exists($readyPaths[0]) || ! file_exists($readyPaths[1]))
                && microtime(true) < $readyDeadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning() && ! $process->isSuccessful()) {
                        $this->fail('An authorization worker exited before reaching the concurrency barrier.');
                    }
                }
                usleep(10000);
            }

            $this->assertFileExists($readyPaths[0], 'First authorization worker did not reach the barrier.');
            $this->assertFileExists($readyPaths[1], 'Second authorization worker did not reach the barrier.');
            file_put_contents($releasePath, 'go', LOCK_EX);

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    'An authorization worker failed with exit code '.$process->getExitCode().'.'
                );
            }

            $decisions = array_map(function (Process $process): string {
                $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

                return (string) ($result['decision'] ?? '');
            }, $processes);

            sort($decisions);
            $this->assertSame(['accept', 'reject'], $decisions);
            $this->assertSame(1, DB::table('radius_device_reservations')->count());
            $this->assertSame(1, DB::table('radius_device_reservations')
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->where('session_id', Session::query()->where('voucher_id', $voucher->id)->value('id'))
                ->where('state', 'reserved')
                ->count());
        } finally {
            if (! file_exists($releasePath)) {
                @file_put_contents($releasePath, 'stop', LOCK_EX);
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (array_merge($readyPaths, [$releasePath]) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($barrierDirectory);
        }
    }

    /**
     * @return array{0: Router, 1: Voucher}
     */
    private function createOneDeviceEntitlement(): array
    {
        $suffix = Str::lower(Str::random(10));
        $tenant = Tenant::create([
            'name' => 'Concurrency '.$suffix,
            'slug' => 'concurrency-'.$suffix,
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Concurrency Router '.$suffix,
        ]);
        app(RadiusRouterCredentialService::class)->optIn($router, 'nas-concurrency-'.$suffix);
        $router->refresh();

        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One-device '.$suffix,
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'data_limit' => 'Unlimited',
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'CONCURRENCY-'.$suffix,
            'quantity' => 1,
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'CONCURRENCY-'.$suffix,
            'status' => 'redeemed',
            'redeemed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        Session::create([
            'tenant_id' => $tenant->id,
            'voucher_id' => $voucher->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'access_mode' => 'radius',
            'status' => 'provisioning',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'pending_revocation' => false,
            'pending_device_ban' => false,
        ]);

        return [$router, $voucher];
    }
}
