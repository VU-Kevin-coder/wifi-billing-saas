<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\RouterCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryPendingRevocations extends Command
{
    protected $signature = 'sessions:retry-revocations';

    protected $description = 'Retry pending router session kicks';

    public function handle(RouterCommandService $commands): int
    {
        $requested = 0;
        $failed = 0;

        Session::withoutTenancy()
            ->with(['router' => fn ($query) => $query->withoutTenancy()])
            ->where('pending_revocation', true)
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($commands, &$requested, &$failed): void {
                foreach ($sessions as $session) {
                    if ($session->router?->access_backend === 'radius'
                        && $commands->requestKick(
                            $session,
                            "revocation:{$session->id}:".($session->pending_revocation_status ?: 'expired'),
                        )) {
                        $requested++;

                        continue;
                    }

                    $failed++;
                    Log::warning('Pending revocation requires operator review because router kick delivery is unavailable.', [
                        'session_id' => $session->id,
                        'router_id' => $session->router_id,
                    ]);
                }
            });

        $this->info("Requested {$requested} router kick(s); {$failed} revocation(s) require operator review.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
