<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SystemHealthService
{
    private const HEARTBEAT_KEY = 'system-health:scheduler-heartbeat';

    private const SNAPSHOT_KEY = 'system-health:snapshot';

    public function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->timestamp, 300);
    }

    public function checkAndNotify(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'scheduler' => $this->checkScheduler(),
            'queue' => $this->checkQueue(),
        ];

        $snapshot = [
            'overall' => $this->overallStatus($checks),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];

        try {
            Cache::put(self::SNAPSHOT_KEY, $snapshot, 300);
        } catch (Throwable $exception) {
            Log::error('Unable to persist the system health snapshot.', [
                'exception' => $exception::class,
            ]);
        }

        $this->notifyOnTransition($snapshot);

        return $snapshot;
    }

    public function current(): array
    {
        try {
            $snapshot = Cache::get(self::SNAPSHOT_KEY);
            $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable $exception) {
            return $this->unknownSnapshot('Cache unavailable');
        }

        if (! is_array($snapshot) || ! is_array($snapshot['checks'] ?? null)) {
            return $this->unknownSnapshot('Health check has not run');
        }

        $snapshot['checks']['scheduler'] = $this->schedulerStatus($heartbeat);
        $snapshot['overall'] = $this->overallStatus($snapshot['checks']);

        return $snapshot;
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['status' => 'healthy', 'message' => 'Database responds'];
        } catch (Throwable $exception) {
            Log::warning('System health database probe failed.', [
                'exception' => $exception::class,
            ]);

            return ['status' => 'unhealthy', 'message' => 'Database unavailable'];
        }
    }

    private function checkCache(): array
    {
        $key = 'system-health:probe:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(16));

        try {
            Cache::put($key, $value, 10);
            $available = Cache::get($key) === $value;
            Cache::forget($key);

            return [
                'status' => $available ? 'healthy' : 'unhealthy',
                'message' => $available ? 'Cache read/write succeeds' : 'Cache read/write failed',
            ];
        } catch (Throwable $exception) {
            Log::warning('System health cache probe failed.', [
                'exception' => $exception::class,
            ]);

            return ['status' => 'unhealthy', 'message' => 'Cache unavailable'];
        }
    }

    private function checkScheduler(): array
    {
        try {
            return $this->schedulerStatus(Cache::get(self::HEARTBEAT_KEY));
        } catch (Throwable $exception) {
            return ['status' => 'unhealthy', 'message' => 'Scheduler heartbeat unavailable'];
        }
    }

    private function schedulerStatus(mixed $heartbeat): array
    {
        $threshold = max(30, (int) config('health.scheduler_stale_after_seconds', 120));
        $fresh = is_numeric($heartbeat) && now()->timestamp - (int) $heartbeat <= $threshold;

        return [
            'status' => $fresh ? 'healthy' : 'unhealthy',
            'message' => $fresh ? 'Scheduler heartbeat is recent' : 'Scheduler heartbeat is stale',
        ];
    }

    private function checkQueue(): array
    {
        if (config('queue.default') === 'sync') {
            return ['status' => 'healthy', 'message' => 'Synchronous queue; no worker required'];
        }

        return ['status' => 'unverified', 'message' => 'Queue worker heartbeat is not configured'];
    }

    private function overallStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'healthy') {
                return 'degraded';
            }
        }

        return 'healthy';
    }

    private function unknownSnapshot(string $message): array
    {
        return [
            'overall' => 'unknown',
            'checked_at' => null,
            'checks' => [
                'database' => ['status' => 'unknown', 'message' => $message],
                'cache' => ['status' => 'unknown', 'message' => $message],
                'scheduler' => ['status' => 'unknown', 'message' => $message],
                'queue' => ['status' => 'unknown', 'message' => $message],
            ],
        ];
    }

    private function notifyOnTransition(array $snapshot): void
    {
        $stateCache = Cache::store();

        try {
            $lock = $stateCache->lock('system-health:alert-lock', 15);
            $locked = $lock->get();
        } catch (Throwable $exception) {
            Log::warning('Shared health-alert locking is unavailable; using local throttling.', [
                'exception' => $exception::class,
            ]);
            $stateCache = Cache::store('file');
            $lock = $stateCache->lock('system-health:alert-lock', 15);
            $locked = $lock->get();
        }

        if (! $locked) {
            return;
        }

        try {
            $previous = $stateCache->get('system-health:last-alert-state');
            $current = $snapshot['overall'];

            if ($previous === $current) {
                return;
            }

            if ($current === 'healthy' && $previous === null) {
                $stateCache->put('system-health:last-alert-state', $current, 2592000);

                return;
            }

            $recipients = $this->adminRecipients($stateCache);
            if ($recipients === []) {
                Log::error('System health changed, but there are no active platform-admin email recipients.');

                return;
            }

            $failedChecks = collect($snapshot['checks'])
                ->filter(fn (array $check): bool => $check['status'] !== 'healthy')
                ->map(fn (array $check, string $name): string => "{$name}: {$check['message']}")
                ->values()
                ->implode("\n");
            $isRecovery = $current === 'healthy';
            $subject = $isRecovery ? 'System health recovered' : 'System health alert';
            $body = $isRecovery
                ? "All monitored system dependencies are healthy again.\nChecked at: {$snapshot['checked_at']}"
                : "System status: {$current}\n{$failedChecks}\nChecked at: {$snapshot['checked_at']}";

            $allSent = true;
            foreach ($recipients as $recipient) {
                try {
                    try {
                        PlatformMailService::configureMailer();
                    } catch (Throwable $exception) {
                        Log::warning('Unable to load platform SMTP settings for a health email; using the application mailer.', [
                            'exception' => $exception::class,
                        ]);
                    }

                    Mail::raw($body, function ($message) use ($recipient, $subject): void {
                        $fromAddress = config('mail.from.address');
                        $fromName = config('mail.from.name');
                        if ($fromAddress) {
                            $message->from($fromAddress, $fromName);
                        }
                        $message->to($recipient)->subject($subject);
                    });
                } catch (Throwable $exception) {
                    $allSent = false;
                    Log::error('Unable to send a system health email alert.', [
                        'recipient' => $recipient,
                        'exception' => $exception::class,
                    ]);
                }
            }

            if ($allSent) {
                $stateCache->put('system-health:last-alert-state', $current, 2592000);
            }
        } catch (Throwable $exception) {
            Log::error('Unable to evaluate system health alert delivery.', [
                'exception' => $exception::class,
            ]);
        } finally {
            $lock->release();
        }
    }

    private function adminRecipients($stateCache): array
    {
        try {
            $recipients = User::withoutGlobalScopes()
                ->role('platform_admin')
                ->whereNull('deactivated_at')
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values()
                ->all();
            $configuredRecipients = array_filter(
                (array) PlatformSetting::get('alerts.platform_emails', []),
                fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL),
            );
            $recipients = array_values(array_unique(array_merge($recipients, $configuredRecipients)));

            if ($recipients !== []) {
                $stateCache->put('system-health:platform-admin-emails', $recipients, 2592000);
            }

            return $recipients;
        } catch (Throwable $exception) {
            Log::warning('Unable to load platform-admin health alert recipients from the database.', [
                'exception' => $exception::class,
            ]);

            return $stateCache->get('system-health:platform-admin-emails', []);
        }
    }
}
