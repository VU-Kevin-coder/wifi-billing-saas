<?php

namespace App\Services\Subscriptions;

use App\Models\PlatformSetting;
use App\Services\Payments\IotecGateway;
use App\Services\Payments\ObPayGateway;
use App\Services\Payments\PawaPayGateway;
use App\Services\Payments\PaymentGatewaySettings;
use RuntimeException;

final class ActiveVoucherSubscriptionGateway implements SubscriptionGateway
{
    public function __construct(
        private readonly ObPayGateway $obpay,
        private readonly PawaPayGateway $pawapay,
        private readonly IotecGateway $iotec,
    ) {}

    public function context(): ?SubscriptionGatewayContext
    {
        $provider = PaymentGatewaySettings::active();

        return $provider ? $this->contextFor($provider) : null;
    }

    public function ready(): bool
    {
        return $this->context() !== null;
    }

    public function canVerify(SubscriptionGatewayContext $context): bool
    {
        $current = $this->contextFor($context->provider);

        return $current !== null && $current->matches($context);
    }

    public function start(
        string $reference,
        int $amount,
        string $currency,
        string $phone,
        SubscriptionGatewayContext $context,
        ?string $returnUrl = null,
    ): ?string {
        $this->assertCharge($amount, $currency, $phone);
        $this->assertCurrentContext($context);

        switch ($context->provider) {
            case 'obpay':
                return $this->startObPay($reference, $amount, $phone, $returnUrl);
            case 'pawapay':
                $this->startPawaPay($reference, $amount, $phone);
                return null;
            case 'iotec':
                $this->startIotec($reference, $amount, $phone);
                return null;
            default:
                throw new RuntimeException('Subscription payment provider is unsupported.');
        }
    }

    public function verify(string $reference, ?SubscriptionGatewayContext $context): ?array
    {
        if (! $context) {
            return null;
        }

        $this->assertCurrentContext($context);
        $response = match ($context->provider) {
            'obpay' => $this->obpay->transaction($reference),
            'pawapay' => $this->pawapay->depositStatus($reference),
            'iotec' => $this->iotec->collectionStatusByExternalId($reference),
            default => null,
        };
        if (! is_array($response)) {
            return null;
        }

        if ($context->provider === 'obpay') {
            if (($response['success'] ?? false) !== true || ! is_array($response['data'] ?? null)) {
                return null;
            }
            $details = $response['data'];
        } else {
            $details = is_array($response['data'] ?? null) ? $response['data'] : $response;
        }

        $providerReference = match ($context->provider) {
            'obpay' => $details['reference'] ?? null,
            'pawapay' => ($details['clientReferenceId'] ?? null) === $reference
                ? ($details['depositId'] ?? null)
                : null,
            'iotec' => $details['externalId'] ?? null,
            default => null,
        };
        $amount = $this->wholeAmount($details['amount'] ?? null);
        $currency = strtoupper((string) ($details['currency'] ?? $details['currencyCode'] ?? ''));
        if ($providerReference !== $reference || $amount === null || $currency !== 'UGX') {
            return null;
        }

        $status = strtoupper((string) ($details['status'] ?? $details['transactionStatus'] ?? ''));
        $normalizedStatus = match ($status) {
            'SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'COMMITTED' => 'paid',
            'FAILED', 'REJECTED', 'REVERSED', 'CANCELLED' => 'failed',
            'PENDING', 'SENTTOVENDOR', 'ACCEPTED', 'SUBMITTED', 'PROCESSING', 'INITIATED' => 'pending',
            default => null,
        };
        if ($normalizedStatus === null) {
            return null;
        }

        return [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $normalizedStatus,
        ];
    }

    private function contextFor(string $provider): ?SubscriptionGatewayContext
    {
        if (! PaymentGatewaySettings::configured($provider)) {
            return null;
        }

        $fingerprint = match ($provider) {
            'obpay' => hash('sha256', (string) PaymentGatewaySettings::obpaySecretKey()."\0".PaymentGatewaySettings::obpayBaseUrl()),
            'pawapay' => hash('sha256', (string) PlatformSetting::getSecret('payment.pawapay.api_token')),
            'iotec' => hash('sha256', implode("\0", [
                (string) PlatformSetting::getSecret('payment.iotec.client_id'),
                (string) PlatformSetting::getSecret('payment.iotec.client_secret'),
                (string) PlatformSetting::get('payment.iotec.wallet_id'),
            ])),
            default => null,
        };
        if ($fingerprint === null) {
            return null;
        }

        $environment = $provider === 'pawapay'
            ? (PlatformSetting::get('payment.pawapay.environment', 'sandbox') === 'production' ? 'live' : 'sandbox')
            : ($provider === 'obpay' && str_contains(PaymentGatewaySettings::obpayBaseUrl(), 'sandbox') ? 'sandbox' : 'live');

        return new SubscriptionGatewayContext($provider, $fingerprint, $environment);
    }

    private function assertCurrentContext(SubscriptionGatewayContext $context): void
    {
        $current = $this->contextFor($context->provider);
        if (! $current || ! $current->matches($context)) {
            throw new RuntimeException('The subscription payment gateway configuration changed.');
        }
    }

    private function assertCharge(int $amount, string $currency, string $phone): void
    {
        if ($amount < 1 || $currency !== 'UGX' || ! preg_match('/^\+?256\d{9}$/D', $phone)) {
            throw new RuntimeException('Invalid subscription payment details.');
        }
    }

    private function startObPay(string $reference, int $amount, string $phone, ?string $returnUrl): ?string
    {
        $response = $this->obpay->collect(
            $reference,
            $amount,
            ltrim($phone, '+'),
            'Wi-Fi subscription',
            null,
            $this->obpay->callbackUrl(),
            $this->isHttpsUrl($returnUrl) ? $returnUrl : null,
        );
        $details = $response['data'] ?? null;
        if (($response['success'] ?? false) !== true
            || ! is_array($details)
            || ($details['reference'] ?? null) !== $reference
            || strtolower((string) ($details['status'] ?? '')) !== 'pending') {
            throw new RuntimeException('OBPay did not confirm the subscription collection request.');
        }

        if (($details['prompt_sent'] ?? null) === false) {
            $checkoutUrl = $details['checkout_url'] ?? null;
            if (! $this->trustedObPayCheckoutUrl($checkoutUrl)) {
                throw new RuntimeException('OBPay did not return a trusted hosted checkout URL.');
            }

            return $checkoutUrl;
        }

        return null;
    }

    private function startPawaPay(string $reference, int $amount, string $phone): void
    {
        $prediction = $this->pawapay->predictProvider(ltrim($phone, '+'));
        $provider = $prediction['provider'] ?? null;
        $canonicalPhone = $prediction['phoneNumber'] ?? null;
        if (! in_array($provider, ['MTN_UGA', 'AIRTEL_UGA'], true)
            || ! is_string($canonicalPhone)
            || ! preg_match('/^256\d{9}$/D', $canonicalPhone)) {
            throw new RuntimeException('PawaPay did not identify a supported Uganda mobile-money account.');
        }

        $response = $this->pawapay->deposit($reference, $reference, $amount, $canonicalPhone, $provider);
        if (($response['status'] ?? null) !== 'ACCEPTED' || ($response['depositId'] ?? null) !== $reference) {
            throw new RuntimeException('PawaPay did not confirm the subscription collection request.');
        }
    }

    private function startIotec(string $reference, int $amount, string $phone): void
    {
        $response = $this->iotec->collect(
            $reference,
            $amount,
            ltrim($phone, '+'),
            'Wi-Fi subscription',
            'Wi-Fi subscription',
        );
        $status = strtoupper((string) ($response['status'] ?? ''));
        if (! is_string($response['id'] ?? null)
            || $response['id'] === ''
            || ($response['externalId'] ?? null) !== $reference
            || ! in_array($status, ['PENDING', 'SENTTOVENDOR', 'SUCCESS'], true)) {
            throw new RuntimeException('ioTec did not confirm the subscription collection request.');
        }
    }

    private function wholeAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function trustedObPayCheckoutUrl(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'checkout.obpay.online'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    private function isHttpsUrl(?string $value): bool
    {
        if (! $value || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
