<?php

namespace App\Services\Subscriptions;

use InvalidArgumentException;

final class SubscriptionGatewayContext
{
    public function __construct(
        public readonly string $provider,
        public readonly string $accountFingerprint,
        public readonly string $environment,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $provider)
            || ! preg_match('/^[a-f0-9]{64}$/D', $accountFingerprint)
            || ! in_array($environment, ['live', 'sandbox'], true)) {
            throw new InvalidArgumentException('Invalid subscription gateway context.');
        }
    }

    public static function fromAttempt(object $attempt): ?self
    {
        if (! is_string($attempt->gateway_provider ?? null)
            || ! is_string($attempt->gateway_account_fingerprint ?? null)
            || ! is_string($attempt->gateway_environment ?? null)) {
            return null;
        }

        try {
            return new self(
                $attempt->gateway_provider,
                $attempt->gateway_account_fingerprint,
                $attempt->gateway_environment,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function matches(self $other): bool
    {
        return $this->provider === $other->provider
            && hash_equals($this->accountFingerprint, $other->accountFingerprint)
            && $this->environment === $other->environment;
    }

    public function databaseAttributes(): array
    {
        return [
            'gateway_provider' => $this->provider,
            'gateway_account_fingerprint' => $this->accountFingerprint,
            'gateway_environment' => $this->environment,
        ];
    }
}
