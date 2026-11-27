<?php

namespace App\Services\Subscriptions;

use RuntimeException;

/**
 * Isolated, deliberately disabled OBPay subscription adapter candidate.
 *
 * NOT production-ready. The repository's voucher fixtures are not evidence of
 * OBPay's actual authenticated transaction contract. Before enabling any
 * implementation, independently establish the response envelope and error
 * semantics; the authoritative reference, amount units/types and currency;
 * which statuses conclusively mean settled, pending or failed (including
 * reversals); and how to bind a result to the correct merchant account and
 * subscription purpose on the shared account. Also establish collect/customer
 * fields, callback routing, idempotency and ambiguous submission recovery.
 *
 * No binding, credentials, requests or checkout are enabled by this class.
 */
final class ObPaySubscriptionGatewayCandidate implements SubscriptionGateway
{
    public function ready(): bool
    {
        return false;
    }

    public function context(): ?SubscriptionGatewayContext
    {
        return null;
    }

    public function canVerify(SubscriptionGatewayContext $context): bool
    {
        return false;
    }

    public function start(
        string $reference,
        int $amount,
        string $currency,
        string $phone,
        SubscriptionGatewayContext $context,
        ?string $returnUrl = null,
    ): ?string
    {
        // Even an apparently successful submission must not create a false
        // pending state while the contract and account binding are unproven.
        throw new RuntimeException('OBPay subscription gateway is not ready.');
    }

    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array
    {
        // In particular, do not query a possibly wrong shared merchant account.
        return null;
    }

    /**
     * Development-only fail-closed boundary for responses from ObPayGateway.
     * An authenticated transport and complete-looking fields alone do not
     * establish settlement or account/purpose ownership. Until the missing
     * contracts above are independently verified, every payload is unknown.
     * This method must not be used to enable checkout.
     */
    public function normalizeAuthenticatedResponse(mixed $response, string $reference): ?array
    {
        return null;
    }
}
