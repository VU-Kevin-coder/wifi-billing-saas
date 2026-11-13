<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class CheckSystemHealth extends Command
{
    protected $signature = 'system:health-check';

    protected $description = 'Check dependencies and report scheduler health';

    public function handle(SystemHealthService $health): int
    {
        try {
            $health->recordSchedulerHeartbeat();
        } catch (\Throwable $exception) {
            $this->error('Unable to write the scheduler heartbeat: '.$exception::class);
        }

        $snapshot = $health->checkAndNotify();
        $this->line("System health: {$snapshot['overall']}");

        return self::SUCCESS;
    }
}
