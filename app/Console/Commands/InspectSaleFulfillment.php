<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InspectSaleFulfillment extends Command
{
    protected $signature = 'payments:inspect-exhausted {tenant : Tenant ID} {--limit=25 : Maximum rows (1-100)}';

    protected $description = 'Inspect exhausted paid-sale fulfillment work for one tenant (read only)';

    public function handle(): int
    {
        $tenant = (string) $this->argument('tenant');
        $limit = (string) $this->option('limit');
        if (! ctype_digit($tenant) || (int) $tenant < 1 || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Provide a positive tenant ID and a limit between 1 and 100.');

            return self::INVALID;
        }

        $query = DB::table('sale_fulfillment_work')->where('tenant_id', (int) $tenant)->where('status', 'exhausted');
        $count = (clone $query)->count();
        $items = $query->orderBy('updated_at')->orderBy('id')->limit((int) $limit)
            ->get(['id', 'transaction_id', 'attempts', 'last_error_class', 'updated_at']);
        $this->info("Tenant {$tenant}: {$count} exhausted work item(s); showing {$items->count()}.");
        $this->table(['Work ID', 'Transaction ID', 'Attempts', 'Last error class', 'Updated at'], $items->map(fn ($item) => (array) $item)->all());

        return self::SUCCESS;
    }
}
