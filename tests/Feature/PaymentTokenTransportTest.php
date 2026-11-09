<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_status_is_read_with_the_token_in_a_header(): void
    {
        $this->getJson('/api/payments/status', ['X-Payment-Token' => self::TOKEN])
            ->assertStatus(202)
            ->assertJson(['status' => 'pending']);
    }

    public function test_activation_also_takes_the_token_from_a_header(): void
    {
        $this->postJson('/api/payments/activate', [], ['X-Payment-Token' => self::TOKEN])
            ->assertStatus(409)                                  // recognised, but not paid yet
            ->assertJson(['status' => 'payment_not_completed']);

        $this->postJson('/api/payments/activate', [])->assertNotFound();
    }

    public function test_a_missing_or_wrong_token_is_not_found(): void
    {
        $this->getJson('/api/payments/status')->assertNotFound()->assertJson(['status' => 'not_found']);
        $this->getJson('/api/payments/status', ['X-Payment-Token' => 'wrong'])->assertNotFound();
    }

    public function test_a_token_in_the_url_is_not_accepted(): void
    {
        $this->getJson('/api/payments/'.self::TOKEN)->assertNotFound();                          // old path style
        $this->postJson('/api/payments/'.self::TOKEN.'/activate')->assertNotFound();
        $this->getJson('/api/payments/status?token='.self::TOKEN)->assertNotFound();              // query string
        $this->getJson('/api/payments/status?X-Payment-Token='.self::TOKEN)->assertNotFound();
    }

    public function test_the_success_page_sends_the_token_in_headers_and_keeps_it_out_of_urls(): void
    {
        $html = $this->withSession(['payment_token' => self::TOKEN])
            ->get('http://acme.localhost/portal/success')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("'X-Payment-Token': paymentToken", $html);
        $this->assertStringContainsString('"\/api\/payments\/status"', $html);
        $this->assertStringContainsString('"\/api\/payments\/activate"', $html);
        // The token is data for the script, never part of a request URL in the page.
        $this->assertStringNotContainsString('/api/payments/'.self::TOKEN, str_replace('\\/', '/', $html));
    }
}
