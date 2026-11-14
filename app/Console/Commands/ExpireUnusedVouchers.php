<?php

namespace App\Console\Commands;

use App\Models\Voucher;
use Illuminate\Console\Command;

class ExpireUnusedVouchers extends Command
{
    protected $signature = 'vouchers:expire-unused';

    protected $description = 'Expire unused vouchers whose validity period has ended';

    public function handle(): int
    {
        $expired = 0;
        $now = now();

        Voucher::withoutTenancy()
            ->where('status', 'unused')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($vouchers) use (&$expired, $now): void {
                $expired += Voucher::withoutTenancy()
                    ->whereIn('id', $vouchers->modelKeys())
                    ->where('status', 'unused')
                    ->where('expires_at', '<=', $now)
                    ->update(['status' => 'expired']);
            });

        $this->info("Expired {$expired} unused voucher(s).");

        return self::SUCCESS;
    }
}
