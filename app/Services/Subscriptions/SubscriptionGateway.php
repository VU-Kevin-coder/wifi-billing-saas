<?php

namespace App\Services\Subscriptions;

/** A subscription-only adapter. verify() MUST query an authenticated provider API,
 * never trust the browser or an unverified callback. No live adapter is supplied.
 */
interface SubscriptionGateway
{
    public function ready(): bool;

    /** A return or exception never proves settlement. */
    public function start(string $reference, int $amount, string $currency, string $phone): void;

    /** Return null when unknown, otherwise authenticated reference, amount, currency and status. */
    public function verify(string $reference): ?array;
}
