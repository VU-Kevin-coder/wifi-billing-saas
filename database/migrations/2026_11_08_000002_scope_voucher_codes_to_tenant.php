<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher codes and batch codes were unique across the whole platform, but
 * every tenant starts with the same default prefix ("KL"), so the second
 * tenant's first batch collided with the first tenant's "KL-001". Existing
 * data already satisfies the narrower per-tenant rule, so this is safe to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_batches', function (Blueprint $table) {
            $table->dropUnique(['batch_code']);
            $table->unique(['tenant_id', 'batch_code']);
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    /**
     * Rolling back re-imposes platform-wide uniqueness and will fail if two
     * tenants have since used the same code; resolve those rows first.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['code']);
        });

        Schema::table('voucher_batches', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'batch_code']);
            $table->unique(['batch_code']);
        });
    }
};
