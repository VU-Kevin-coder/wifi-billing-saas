<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\RouterCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireSessions extends Command
{
    protected $signature = 'sessions:expire';

    protected $description = 'Expire elapsed sessions and request router session kicks';

    public function handle(RouterCommandService $commands): int
    {
        $queuedCount = 0;
        $retryCount = 0;

        Session::withoutTenancy()
            ->with([
                'router' => fn ($query) => $query->withoutTenancy(),
                'voucher' => fn ($query) => $query->withoutTenancy(),
            ])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($commands, &$queuedCount, &$retryCount): void {
                foreach ($sessions as $session) {
                    $session->update([
                        'status' => 'disconnecting',
                        'pending_revocation' => true,
                        'pending_revocation_status' => 'expired',
                    ]);

                    if ($session->router?->access_backend === 'radius'
                        && $commands->requestKick(
                            $session,
                            "revocation:{$session->id}:expired",
                        )) {
                        $queuedCount++;

                        continue;
                    }

                    $retryCount++;
                    Log::error('Expired session revocation is pending without a router kick request.', [
                        'session_id' => $session->id,
                        'router_id' => $session->router_id,
                    ]);
                }
            });

        $this->info("Requested {$queuedCount} router kick(s); {$retryCount} revocation(s) remain pending.");

        return $retryCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
