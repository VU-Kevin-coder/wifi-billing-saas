<?php

namespace App\Services\Subscriptions;

use RuntimeException;

final class UnavailableGateway implements SubscriptionGateway
{
    public function ready(): bool
    {
        return false;
    }

    public function start(string $reference, int $amount, string $currency, string $phone): void
    {
        throw new RuntimeException('Subscription gateway unavailable.');
    }

    public function verify(string $reference): ?array
    {
        throw new RuntimeException('Subscription gateway unavailable.');
    }
}
