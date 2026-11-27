<?php

namespace App\Services\Subscriptions;

/** A subscription-only adapter. verify() MUST query an authenticated provider API,
 * never trust the browser or an unverified callback.
 */
interface SubscriptionGateway
{
    public function context(): ?SubscriptionGatewayContext;

    public function ready(): bool;

    public function canVerify(SubscriptionGatewayContext $context): bool;

    /** A return or exception never proves settlement; hosted checkout URLs are optional. */
    public function start(
        string $reference,
        int $amount,
        string $currency,
        string $phone,
        SubscriptionGatewayContext $context,
        ?string $returnUrl = null,
    ): ?string;

    /** Return null when unknown, otherwise authenticated reference, amount, currency and status. */
    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array;
}
