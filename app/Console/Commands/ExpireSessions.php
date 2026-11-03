<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\RouterConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireSessions extends Command
{
    protected $signature = 'sessions:expire';

    protected $description = 'Expire elapsed sessions and disconnect active MikroTik users';

    public function handle(RouterConnectionService $routerService): int
    {
        $expiredCount = 0;
        $retryCount = 0;

        Session::withoutGlobalScopes()
            ->with(['router', 'voucher'])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($routerService, &$expiredCount, &$retryCount): void {
                foreach ($sessions as $session) {
                    if ($session->router && $session->voucher) {
                        try {
                            $disconnected = $routerService->disconnectUser(
                                $session->router,
                                $session->voucher->code
                            );

                            if (! $disconnected) {
                                Log::info('Expired voucher had no active MikroTik hotspot session.', [
                                    'session_id' => $session->id,
                                    'router_id' => $session->router_id,
                                    'voucher_id' => $session->voucher_id,
                                ]);
                            }
                        } catch (Throwable $exception) {
                            $retryCount++;
                            Log::error('Unable to disconnect expired MikroTik session; it will be retried.', [
                                'session_id' => $session->id,
                                'router_id' => $session->router_id,
                                'voucher_id' => $session->voucher_id,
                                'exception' => $exception,
                            ]);

                            continue;
                        }
                    }

                    $session->update(['status' => 'expired']);
                    $expiredCount++;
                }
            });

        $this->info("Expired {$expiredCount} session(s); {$retryCount} disconnect(s) will be retried.");

        return $retryCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
