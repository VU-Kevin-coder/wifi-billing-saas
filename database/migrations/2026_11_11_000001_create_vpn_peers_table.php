<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('vpn_subnet', 18)->nullable()->unique();
        });

        Schema::create('vpn_peers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('public_key', 44)->unique();
            $table->text('private_key')->nullable();
            $table->string('tunnel_ip', 15)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('config_downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peers');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['vpn_subnet']);
            $table->dropColumn('vpn_subnet');
        });
    }
};
