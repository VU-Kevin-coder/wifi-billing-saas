<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Isolated, not wired to checkout or callbacks. Whole UGX only. The caller
 * must create the target and mapping in the SAME outer DB transaction and
 * commit before any external provider request. Inputs must be trusted local
 * configuration, never a callback. No update/delete interface is provided.
 *
 * This is not a backfill or reconciliation tool: historical in-flight rows
 * require verified account/environment, amount, currency and references and
 * explicit collision handling before any routing cutover. SQLite foreign keys
 * must remain enabled on every SQLite connection. This schema does not enforce
 * row immutability, target-reference/amount equality, target type, invoice
 * tenant consistency, or canonical UUID/provider/account syntax against raw
 * SQL; those checks are application-only. Database writers can still mutate or
 * delete mappings and targets. Resolve these gaps (including DB permissions or
 * triggers, target mutation and restricted SQL access) before integration.
 * Current voucher references use ONL-... rather than canonical UUIDs and are
 * intentionally incompatible. Cross-table legacy reference collisions, old
 * in-flight reconciliation, rollout/reference compatibility, provider/account/
 * environment semantics and real concurrency require review and tests before
 * cutover. Disable SQL query/bind logs; this
 * service itself logs nothing and strips SQL exception bindings.
 */
final class IsolatedCollectionMappingStore
{
    /**
     * The target is a voucher-sale transaction or subscription attempt, not a
     * voucher or invoice. Ref must match that target's local reference.
     */
    public function create(mixed $reference, mixed $provider, mixed $accountKey, mixed $environment,
        mixed $tenantId, mixed $purpose, mixed $targetId, mixed $amount, mixed $currency): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('A caller-owned database transaction is required.');
        }
        if (! self::reference($reference)
            || ! is_string($provider) || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $provider)
            || ! is_string($accountKey) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $accountKey)
            || ! in_array($environment, ['live', 'sandbox'], true)
            || ! is_int($tenantId) || $tenantId < 1
            || ! in_array($purpose, ['voucher', 'subscription'], true)
            || ! is_int($targetId) || $targetId < 1
            || ! is_int($amount) || $amount < 1 || $currency !== 'UGX') {
            throw new InvalidArgumentException('Invalid collection mapping.');
        }

        try {
            if (DB::connection()->getDriverName() === 'sqlite'
                && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
                throw new RuntimeException('SQLite foreign key enforcement is required.');
            }
            if (! DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['id'])) {
                throw new InvalidArgumentException('Invalid collection target.');
            }
            if ($purpose === 'voucher') {
                $target = DB::table('transactions')->where('id', $targetId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
                if (! $target || $target->type !== 'voucher_sale' || $target->reference !== $reference
                    || self::wholeUgx($target->amount) !== $amount) {
                    throw new InvalidArgumentException('Invalid collection target.');
                }
            } else {
                $target = DB::table('subscription_attempts')->where('id', $targetId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
                if (! $target || $target->reference !== $reference) {
                    throw new InvalidArgumentException('Invalid collection target.');
                }
                $invoice = DB::table('subscription_invoices')->where('id', $target->subscription_invoice_id)
                    ->where('tenant_id', $tenantId)->lockForUpdate()->first();
                if (! $invoice || $invoice->currency !== 'UGX' || self::wholeUgx($invoice->amount) !== $amount) {
                    throw new InvalidArgumentException('Invalid collection target.');
                }
            }
            DB::table('isolated_collection_mappings')->insert([
                'local_reference' => $reference, 'provider' => $provider, 'account_key' => $accountKey,
                'environment' => $environment, 'tenant_id' => $tenantId, 'purpose' => $purpose,
                'voucher_transaction_id' => $purpose === 'voucher' ? $targetId : null,
                'subscription_attempt_id' => $purpose === 'subscription' ? $targetId : null,
                'amount' => $amount, 'currency' => $currency, 'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // SQL exceptions can contain bindings. Never retain the exception.
            throw new RuntimeException('Collection mapping could not be stored.');
        }
    }

    /** Read only; malformed/unknown references never resolve. No routing occurs. */
    public function find(mixed $reference): ?object
    {
        if (! self::reference($reference)) {
            return null;
        }
        try {
            $row = DB::table('isolated_collection_mappings')->where('local_reference', $reference)->first();

            return $row && $row->local_reference === $reference ? $row : null;
        } catch (QueryException $e) {
            throw new RuntimeException('Collection mapping could not be read.');
        }
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /** Parse a DB integer or exact .00 decimal without float conversion. */
    private static function wholeUgx(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.00)?$/D', (string) $value, $m)) {
            return null;
        }
        $digits = $m[1];
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }
        $amount = (int) $digits;

        return $amount > 0 ? $amount : null;
    }
}
