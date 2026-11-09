<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payment token unlocks the voucher code, so it must never appear in a
 * URL (proxy logs, nginx access logs, Referer headers, browser history).
 */
class PaymentTokenTransportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'transport-token-0123456789abcdef0123456789abcdef0123456789abcdef01';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        Transaction::create([
            'tenant_id' => $this->tenant->id, 'type' => 'voucher_sale', 'amount' => 1000, 'status' => 'pending',
            'reference' => 'ONL-TRANSPORT', 'payment_token_hash' => hash('sha256', self::TOKEN), 'meta' => [],
        ]);
    }

    #[Test]
    public function statusIsReadWithTheTokenInAHeader(): void
    {
        $this->getJson('/api/payments/status', ['X-Payment-Token' => self::TOKEN])
            ->assertStatus(202)
            ->assertJson(['status' => 'pending']);
    }

    #[Test]
    public function activationAlsoTakesTheTokenFromAHeader(): void
    {
        $this->postJson('/api/payments/activate', [], [
            'X-Payment-Token' => self::TOKEN,
            'X-Idempotency-Key' => 'activation-key-transport-test-001',
        ])
            ->assertStatus(409)                                  // recognised, but not paid yet
            ->assertJson(['status' => 'payment_not_completed']);

        $this->postJson('/api/payments/activate', [], [
            'X-Idempotency-Key' => 'activation-key-transport-test-002',
        ])->assertNotFound();
    }

    #[Test]
    public function activationRequiresAndRejectsReusedIdempotencyKeys(): void
    {
        $this->postJson('/api/payments/activate')
            ->assertUnprocessable()
            ->assertJson(['message' => 'A valid idempotency key is required.']);
        $this->postJson('/api/payments/activate?_idempotency_key=query-key-transport-test-001')
            ->assertUnprocessable();

        $headers = ['X-Idempotency-Key' => 'activation-key-reuse-test-001'];
        $this->postJson('/api/payments/activate', [], $headers)->assertNotFound();
        $this->postJson('/api/payments/activate', [], $headers)
            ->assertStatus(409)
            ->assertJson(['message' => 'This request has already been submitted. Refresh before trying again.']);
    }

    #[Test]
    public function aMissingOrWrongTokenIsNotFound(): void
    {
        $this->getJson('/api/payments/status')->assertNotFound()->assertJson(['status' => 'not_found']);
        $this->getJson('/api/payments/status', ['X-Payment-Token' => 'wrong'])->assertNotFound();
    }

    #[Test]
    public function aTokenInTheUrlIsNotAccepted(): void
    {
        $this->getJson('/api/payments/'.self::TOKEN)->assertNotFound();                          // old path style
        $this->postJson('/api/payments/'.self::TOKEN.'/activate')->assertNotFound();
        $this->getJson('/api/payments/status?token='.self::TOKEN)->assertNotFound();              // query string
        $this->getJson('/api/payments/status?X-Payment-Token='.self::TOKEN)->assertNotFound();
    }

    #[Test]
    public function theSuccessPageSendsTheTokenInHeadersAndKeepsItOutOfUrls(): void
    {
        $response = $this->withSession(['payment_token' => self::TOKEN])
            ->get('http://acme.localhost/portal/success')
            ->assertOk()
            ->assertViewIs('portal.success')
            ->assertViewHas('paymentToken', self::TOKEN);
        $html = $response->getContent();

        $this->assertStringContainsString('X-Payment-Token', $html);
        $this->assertStringContainsString('"\/api\/payments\/status"', $html);
        $this->assertStringContainsString('"\/api\/payments\/activate"', $html);
        // The token is data for the script, never part of a request URL in the page.
        $this->assertStringNotContainsString('/api/payments/'.self::TOKEN, str_replace('\\/', '/', $html));
    }
}
