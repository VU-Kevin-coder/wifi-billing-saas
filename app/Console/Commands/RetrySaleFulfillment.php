<?php

namespace App\Console\Commands;

use App\Services\SaleFulfillmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetrySaleFulfillment extends Command
{
    protected $signature = 'payments:retry-fulfillment';

    protected $description = 'Retry bounded, explicitly recorded paid-sale fulfillment work';

    public function handle(SaleFulfillmentService $fulfillment): int
    {
        // No scan of historic sales: only work recorded atomically with confirmation.
        $work = DB::table('sale_fulfillment_work')->whereIn('status', ['pending', 'running'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('updated_at')->orderBy('id')->limit(25)->get();
        $errors = 0;
        foreach ($work as $item) {
            try {
                $fulfillment->attempt((int) $item->tenant_id, (int) $item->transaction_id);
            } catch (\Throwable $exception) {
                $errors++;
                Log::error('Unable to retry paid sale fulfillment.', [
                    'tenant_id' => $item->tenant_id, 'transaction_id' => $item->transaction_id,
                    'exception' => $exception::class,
                ]);
            }
        }
        $this->info("Considered {$work->count()} paid-sale work item(s); {$errors} worker error(s).");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
