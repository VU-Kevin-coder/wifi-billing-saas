<?php

namespace App\Jobs;

use App\Services\RouterCommandService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverRouterCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $routerCommandId) {}

    public function handle(RouterCommandService $commands): void
    {
        $commands->deliverViaApi($this->routerCommandId);
    }
}
