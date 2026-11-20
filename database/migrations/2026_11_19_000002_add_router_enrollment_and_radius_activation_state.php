<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->boolean('enrolled')->default(false);
            $table->boolean('pending_radius_activation')->default(false);
            $table->unsignedTinyInteger('radius_access_reject_streak')->default(0);
            $table->timestamp('radius_activation_paused_at')->nullable();
            $table->string('radius_readiness_status', 16)->default('not_checked');
            $table->timestamp('radius_readiness_checked_at')->nullable();
        });

        Schema::table('router_onboardings', function (Blueprint $table): void {
            $table->string('bootstrap_token_hash', 64)->nullable()->change();
            $table->text('bootstrap_script')->nullable()->change();
            $table->text('bootstrap_command')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn([
                'enrolled',
                'pending_radius_activation',
                'radius_access_reject_streak',
                'radius_activation_paused_at',
                'radius_readiness_status',
                'radius_readiness_checked_at',
            ]);
        });
    }
};
