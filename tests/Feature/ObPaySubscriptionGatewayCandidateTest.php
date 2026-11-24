<?php

namespace Tests\Feature;

use App\Services\Payments\ObPayGateway;
use App\Services\Subscriptions\ObPaySubscriptionGatewayCandidate;
use App\Services\Subscriptions\SubscriptionGateway;
use App\Services\Subscriptions\UnavailableGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use TypeError;

final class ObPaySubscriptionGatewayCandidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.obpay.base_url', 'https://obpay.invalid/api');
        config()->set('services.obpay.secret_key', 'fake-key-not-a-credential');
        Http::preventStrayRequests();
    }

    public function test_candidate_is_not_bound_or_ready_and_never_sends_or_verifies(): void
    {
        Http::fake();
        $adapter = new ObPaySubscriptionGatewayCandidate;
        $this->assertInstanceOf(UnavailableGateway::class, app(SubscriptionGateway::class));
        $this->assertFalse($adapter->ready());
        $this->assertNull($adapter->verify('ref-1'));
        try {
            $adapter->start('ref-1', 1000, 'UGX', '256700000000');
            $this->fail('A disabled adapter must not start a charge.');
        } catch (RuntimeException $e) {
            $this->assertSame('OBPay subscription gateway is not ready.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function unprovenResponses(): array
    {
        $complete = ['reference' => 'ref-1', 'amount' => 1000, 'currency' => 'UGX', 'status' => 'SUCCESS'];

        return [
            'plausible fixture is not provider contract' => [['data' => $complete]],
            'pending' => [['data' => array_replace($complete, ['status' => 'pending'])]],
            'failed' => [['data' => array_replace($complete, ['status' => 'failed'])]],
            'missing reference' => [['data' => array_diff_key($complete, ['reference' => true])]],
            'missing amount' => [['data' => array_diff_key($complete, ['amount' => true])]],
            'missing currency' => [['data' => array_diff_key($complete, ['currency' => true])]],
            'missing status' => [['data' => array_diff_key($complete, ['status' => true])]],
            'different reference' => [['data' => array_replace($complete, ['reference' => 'other'])]],
            'fractional amount' => [['data' => array_replace($complete, ['amount' => 1000.5])]],
            'numeric string amount' => [['data' => array_replace($complete, ['amount' => '1000'])]],
            'wrong currency' => [['data' => array_replace($complete, ['currency' => 'USD'])]],
            'unsupported status' => [['data' => array_replace($complete, ['status' => 'reversed'])]],
            'contradictory envelope' => [['success' => false, 'data' => $complete]],
            'different envelope' => [$complete],
            'empty' => [[]],
        ];
    }

    #[DataProvider('unprovenResponses')]
    public function test_low_level_fake_http_payloads_are_all_unknown(array $payload): void
    {
        Http::fake(['https://obpay.invalid/api/transactions/*' => Http::response($payload)]);
        $response = (new ObPayGateway)->transaction('ref-1');
        $this->assertNull((new ObPaySubscriptionGatewayCandidate)->normalizeAuthenticatedResponse($response, 'ref-1'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://obpay.invalid/api/transactions/ref-1'
            && $request->hasHeader('Authorization', 'Bearer fake-key-not-a-credential'));
    }

    public function test_non_array_payloads_are_unknown(): void
    {
        $adapter = new ObPaySubscriptionGatewayCandidate;
        foreach ([null, false, true, 123, 'SUCCESS', new \stdClass] as $value) {
            $this->assertNull($adapter->normalizeAuthenticatedResponse($value, 'ref-1'));
        }
    }

    public function test_low_level_fake_http_errors_are_not_interpreted_as_payment_outcomes(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'SUCCESS']], 500)]);
        try {
            (new ObPayGateway)->transaction('ref-1');
            $this->fail('HTTP failure should throw, never become settlement.');
        } catch (RequestException $e) {
            $this->assertNull((new ObPaySubscriptionGatewayCandidate)->normalizeAuthenticatedResponse(null, 'ref-1'));
        }
    }

    public function test_invalid_json_cannot_be_normalized_as_success(): void
    {
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'application/json'])]);
        try {
            (new ObPayGateway)->transaction('ref-1');
            $this->fail('The low-level array return type rejects malformed JSON.');
        } catch (TypeError $e) {
            $this->assertNull((new ObPaySubscriptionGatewayCandidate)->normalizeAuthenticatedResponse(null, 'ref-1'));
        }
    }
}
