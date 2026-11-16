<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Voucher;

/** Fail closed for online-sale vouchers while retaining prepaid vouchers. */
class RadiusVoucherEligibility
{
    public function permits(Voucher $voucher): bool
    {
        if ($voucher->status !== 'redeemed') {
            return false;
        }

        return $this->salePermits($voucher);
    }

    public function salePermits(Voucher $voucher): bool
    {
        if ($voucher->sale_transaction_id === null) {
            return true; // Pre-issued vouchers need not have an online sale.
        }

        $sale = Transaction::withoutTenancy()
            ->where('tenant_id', $voucher->tenant_id)
            ->where('type', 'voucher_sale')
            ->where('status', 'completed')
            ->find($voucher->sale_transaction_id);

        return (bool) $sale;
    }
}
