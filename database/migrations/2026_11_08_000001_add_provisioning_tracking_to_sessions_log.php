<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_log', function (Blueprint $table) {
            // How many times the watchdog re-queued a failed activation command.
            $table->unsignedTinyInteger('provisioning_requeues')->default(0)->after('status');
            // Set once when the operator is alerted, so each session alerts at most once.
            $table->timestamp('provisioning_alerted_at')->nullable()->after('provisioning_requeues');
            // When the site agent confirmed that router access exists.
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_alerted_at');

            $table->index(['status', 'started_at'], 'sessions_log_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_log', function (Blueprint $table) {
            $table->dropIndex('sessions_log_status_started_idx');
            $table->dropColumn(['provisioning_requeues', 'provisioning_alerted_at', 'provisioned_at']);
        });
    }
};
