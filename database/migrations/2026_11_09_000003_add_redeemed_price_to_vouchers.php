<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue is recognised when a voucher is redeemed, at the package price of
 * that moment. Without a snapshot, editing a package price silently rewrote
 * the revenue history of every voucher already redeemed. Existing rows stay
 * NULL and keep using the package's current price (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->decimal('redeemed_price', 12, 2)->nullable()->after('redeemed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('redeemed_price');
        });
    }
};
