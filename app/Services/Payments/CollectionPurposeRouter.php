<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Closure;

/**
 * NOT wired into checkout or webhooks. This is a routing guard, not a payment
 * verifier, mapping repository, or idempotency mechanism.
 *
 * Integration contract:
 * - $resolve($reference) returns ALL persisted mappings for that reference,
 *   including across tenants, accounts, providers and purposes, as a list.
 *   A database-enforced global reference uniqueness constraint is required.
 * - $verify($provider, $account, $reference) independently queries or verifies
 *   the provider with account-bound credentials and returns authoritative,
 *   normalized payment facts. Never return callback assertions as facts.
 * - The four route arguments come from a trusted local collection context,
 *   not a callback purpose or callback-selected tenant/account. The verifier
 *   and resolver must not conceal errors, duplicates or ambiguous results.
 * - Mapping creation must be atomic with the local payment attempt before any
 *   provider call. Mappings must be immutable. Handlers must independently
 *   lock/revalidate their target, enforce authorization, idempotency and safe
 *   transaction/fulfillment semantics; the router cannot provide exactly-once.
 *
 * Provider payment facts have no tenant field: tenant belongs to the trusted
 * persisted mapping, not to a provider callback. Exceptions propagate so that
 * infrastructure errors cannot look like successful processing.
 */
final class CollectionPurposeRouter
{
    private Closure $resolve;

    private Closure $verify;

    /** @var array<string, Closure> */
    private array $handlers = [];

    /**
     * Mappings: reference, provider, account, tenant, purpose, target,
     * amount (integer minor units), currency (uppercase ISO code).
     * Verified payment: reference, provider, account, transaction_id, status,
     * amount (same integer units), currency. Status must be exactly 'paid'.
     * Handlers receive only these validated fields, never the callback payload.
     *
     * @param  callable(string): mixed  $resolve
     * @param  callable(string, string, string): mixed  $verify
     * @param  array<string, callable>  $handlers  Keys: voucher and/or subscription.
     */
    public function __construct(callable $resolve, callable $verify, array $handlers = [])
    {
        $this->resolve = Closure::fromCallable($resolve);
        $this->verify = Closure::fromCallable($verify);
        foreach ($handlers as $purpose => $handler) {
            if (! in_array($purpose, ['voucher', 'subscription'], true) || ! is_callable($handler)) {
                throw new \InvalidArgumentException('Only explicit voucher and subscription handlers are allowed.');
            }
            $this->handlers[$purpose] = Closure::fromCallable($handler);
        }
    }

    /** Returns routed or not_routed. Exceptions are intentionally not swallowed. */
    public function route(mixed $reference, mixed $provider, mixed $account, mixed $tenant): string
    {
        foreach ([$reference, $provider, $account, $tenant] as $value) {
            if (! self::identifier($value)) {
                return 'not_routed';
            }
        }

        $rows = ($this->resolve)($reference);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            return 'not_routed';
        }
        $mapping = $rows[0];
        foreach (['reference', 'provider', 'account', 'tenant', 'purpose', 'target'] as $key) {
            if (! self::identifier($mapping[$key] ?? null)) {
                return 'not_routed';
            }
        }
        if ($mapping['reference'] !== $reference || $mapping['provider'] !== $provider
            || $mapping['account'] !== $account || $mapping['tenant'] !== $tenant
            || ! in_array($mapping['purpose'], ['voucher', 'subscription'], true)
            || ! isset($this->handlers[$mapping['purpose']])
            || ! self::money($mapping['amount'] ?? null, $mapping['currency'] ?? null)) {
            return 'not_routed';
        }

        $payment = ($this->verify)($provider, $account, $reference);
        if (! is_array($payment) || ! self::identifier($payment['transaction_id'] ?? null)
            || ($payment['status'] ?? null) !== 'paid'
            || ($payment['reference'] ?? null) !== $reference
            || ($payment['provider'] ?? null) !== $provider
            || ($payment['account'] ?? null) !== $account
            || ! self::money($payment['amount'] ?? null, $payment['currency'] ?? null)
            || $payment['amount'] !== $mapping['amount']
            || $payment['currency'] !== $mapping['currency']) {
            return 'not_routed';
        }

        $mapping = array_intersect_key($mapping, array_flip(['reference', 'provider', 'account', 'tenant', 'purpose', 'target', 'amount', 'currency']));
        $payment = array_intersect_key($payment, array_flip(['reference', 'provider', 'account', 'transaction_id', 'status', 'amount', 'currency']));
        ($this->handlers[$mapping['purpose']])($mapping, $payment);

        return 'routed';
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private static function money(mixed $amount, mixed $currency): bool
    {
        return is_int($amount) && $amount > 0 && is_string($currency)
            && preg_match('/^[A-Z]{3}$/D', $currency) === 1;
    }
}
