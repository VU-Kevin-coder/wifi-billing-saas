<?php

namespace App\Services\Subscriptions;

use RuntimeException;

final class UnavailableGateway implements SubscriptionGateway
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
        throw new RuntimeException('Subscription gateway unavailable.');
    }

    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array
    {
        throw new RuntimeException('Subscription gateway unavailable.');
    }
}
