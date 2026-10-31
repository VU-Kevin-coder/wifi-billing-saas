<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('phone')->constrained('packages')->nullOnDelete();
            $table->string('email')->nullable()->after('package_id');
            $table->string('mac_address')->nullable()->after('email');
            $table->string('status')->default('active')->after('mac_address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn(['package_id', 'email', 'mac_address', 'status']);
        });
    }
};
