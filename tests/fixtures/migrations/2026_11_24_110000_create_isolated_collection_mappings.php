<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported collection mapping database.');
        }
        // MySQL before 8.0.16 parses but ignores CHECK. MariaDB is not verified.
        if ($driver === 'mysql') {
            $version = (string) DB::selectOne('select version() as version')->version;
            if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.16', '<')) {
                throw new RuntimeException('Collection mapping requires MySQL 8.0.16 or later (not MariaDB).');
            }
        }

        if ($driver === 'sqlite' && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            throw new RuntimeException('SQLite foreign key enforcement must be enabled.');
        }
        Schema::table('subscription_attempts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'collection_attempt_tenant_id_unique');
        });
        $id = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
        };
        $foreignId = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'BIGINT';
        $amountCheck = $driver === 'sqlite' ? "amount > 0 AND typeof(amount) = 'integer'" : 'amount > 0';
        // MySQL's default collation may otherwise treat different cases as equal.
        $environment = $driver === 'mysql' ? 'BINARY environment' : 'environment';
        $currency = $driver === 'mysql' ? 'BINARY currency' : 'currency';
        $purpose = $driver === 'mysql' ? 'BINARY purpose' : 'purpose';
        // All mappings represent whole UGX until currency exponent and provider
        // semantics are explicitly validated. No historical mappings are invented.
        DB::statement("CREATE TABLE isolated_collection_mappings (
            id $id,
            local_reference VARCHAR(36) NOT NULL UNIQUE,
            provider VARCHAR(32) NOT NULL,
            account_key VARCHAR(128) NOT NULL,
            environment VARCHAR(16) NOT NULL,
            tenant_id $foreignId NOT NULL,
            purpose VARCHAR(16) NOT NULL,
            voucher_transaction_id $foreignId NULL UNIQUE,
            subscription_attempt_id $foreignId NULL UNIQUE,
            amount BIGINT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            created_at TIMESTAMP NOT NULL,
            CONSTRAINT collection_ref_length CHECK (length(local_reference) = 36),
            CONSTRAINT collection_provider_length CHECK (length(provider) BETWEEN 1 AND 32),
            CONSTRAINT collection_account_length CHECK (length(account_key) BETWEEN 1 AND 128),
            CONSTRAINT collection_environment CHECK ($environment IN ('live', 'sandbox')),
            CONSTRAINT collection_currency CHECK ($currency = 'UGX'),
            CONSTRAINT collection_amount CHECK ($amountCheck),
            CONSTRAINT collection_target CHECK (
                ($purpose = 'voucher' AND voucher_transaction_id IS NOT NULL AND subscription_attempt_id IS NULL)
                OR ($purpose = 'subscription' AND subscription_attempt_id IS NOT NULL AND voucher_transaction_id IS NULL)
            ),
            CONSTRAINT collection_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
            CONSTRAINT collection_voucher_tenant_fk FOREIGN KEY (tenant_id, voucher_transaction_id) REFERENCES transactions(tenant_id, id) ON DELETE RESTRICT,
            CONSTRAINT collection_subscription_tenant_fk FOREIGN KEY (tenant_id, subscription_attempt_id) REFERENCES subscription_attempts(tenant_id, id) ON DELETE RESTRICT
        )");
    }

    public function down(): void
    {
        if (Schema::hasTable('isolated_collection_mappings') && DB::table('isolated_collection_mappings')->exists()) {
            throw new RuntimeException('Refusing to drop nonempty collection mappings.');
        }
        Schema::dropIfExists('isolated_collection_mappings');
        Schema::table('subscription_attempts', function (Blueprint $table): void {
            $table->dropUnique('collection_attempt_tenant_id_unique');
        });
    }
};
