<?php

namespace App\Console\Commands;

use App\Models\BlockedDevice;
use App\Services\SiteAgentCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryPendingDeviceActions extends Command
{
    protected $signature = 'routers:retry-device-actions';

    protected $description = 'Retry pending MikroTik MAC block and unblock commands';

    public function handle(SiteAgentCommandService $commands): int
    {
        $queued = 0;
        $failed = 0;

        BlockedDevice::withoutGlobalScopes()
            ->whereIn('status', ['pending', 'ban_failed', 'unban_pending', 'unban_failed'])
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($commands, &$queued, &$failed): void {
                foreach ($devices as $device) {
                    try {
                        $commands->retryDeviceAction($device);
                        $queued++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $device->update([
                            'status' => in_array($device->status, ['pending', 'ban_failed'], true)
                                ? 'ban_failed'
                                : 'unban_failed',
                            'last_error' => $exception->getMessage(),
                        ]);
                        Log::warning('Unable to queue a pending router device action.', [
                            'blocked_device_id' => $device->id,
                            'router_id' => $device->router_id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        $this->info("Queued {$queued} pending device action(s); {$failed} could not be queued.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
