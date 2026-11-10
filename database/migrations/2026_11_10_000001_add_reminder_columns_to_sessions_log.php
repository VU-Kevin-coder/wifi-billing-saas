<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_log', function (Blueprint $table) {
            // Set once, atomically, when an expiry reminder is claimed for this session.
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('provisioned_at');
            // Short unguessable token for the customer's "my plan / renew" link (SMS-friendly).
            $table->string('renew_token', 20)->nullable()->unique()->after('expiry_reminder_sent_at');
            $table->index(['status', 'expires_at', 'expiry_reminder_sent_at'], 'sessions_log_reminder_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_log', function (Blueprint $table) {
            $table->dropIndex('sessions_log_reminder_idx');
            $table->dropUnique(['renew_token']);
            $table->dropColumn(['expiry_reminder_sent_at', 'renew_token']);
        });
    }
};
