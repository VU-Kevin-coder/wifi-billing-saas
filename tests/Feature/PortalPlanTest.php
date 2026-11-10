<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalPlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->tenant = Tenant::create(['name' => 'Acme WiFi', 'slug' => 'acme', 'status' => 'active']);
        $this->package = $this->package($this->tenant);
    }

    private function package(Tenant $tenant, bool $published = true): Package
    {
        return Package::create([
            'tenant_id' => $tenant->id, 'name' => 'Daily Pass', 'price' => 2000, 'currency' => 'UGX', 'duration_value' => 1,
            'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1, 'is_published' => $published, 'data_limit' => '5GB',
        ]);
    }

    private function createSession(Tenant $tenant, Package $package, string $code, ?string $phone = '0771234567', int $minutesLeft = 90, string $status = 'active'): Session
    {
        $batch = VoucherBatch::withoutTenancy()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'batch_code' => 'P-1'],
            ['package_id' => $package->id, 'quantity' => 5]
        );
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $package->id,
            'code' => $code, 'status' => 'redeemed', 'redeemed_by' => $phone,
        ]);

        return Session::withoutTenancy()->create([
            'tenant_id' => $tenant->id, 'voucher_id' => $voucher->id, 'package_id' => $package->id, 'status' => $status,
            'started_at' => now()->subHours(2), 'expires_at' => now()->addMinutes($minutesLeft),
        ]);
    }

    private function open(Session $session, string $host = 'acme')
    {
        return $this->get("http://{$host}.localhost/portal/r/".$session->fresh()->renew_token);
    }

    // ── Finding a plan ──────────────────────────────────────────────────────

    public function test_a_customer_finds_their_plan_with_their_voucher_code(): void
    {
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0001');

        $response = $this->post('http://acme.localhost/portal/plan', ['voucher_code' => ' acme-0001 ']);

        $session = $session->fresh();
        $this->assertNotNull($session->renew_token);
        $response->assertRedirect('http://acme.localhost/portal/r/'.$session->renew_token);
    }

    public function test_an_unknown_code_and_a_never_used_code_get_the_same_answer(): void
    {
        $batch = VoucherBatch::create(['tenant_id' => $this->tenant->id, 'package_id' => $this->package->id, 'batch_code' => 'U-1', 'quantity' => 1]);
        Voucher::create(['tenant_id' => $this->tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $this->package->id, 'code' => 'UNUSED-1', 'status' => 'unused']);

        $unknown = $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'NOPE-0000']);
        $unused = $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'UNUSED-1']);

        $unknown->assertSessionHasErrors(['voucher_code' => 'We could not find a plan for that code.']);
        $unused->assertSessionHasErrors(['voucher_code' => 'We could not find a plan for that code.']);
    }

    public function test_a_code_from_another_business_finds_nothing(): void
    {
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $this->createSession($other, $this->package($other), 'BETA-0001');

        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'BETA-0001'])->assertSessionHasErrors('voucher_code');
    }

    public function test_the_lookup_page_renders(): void
    {
        $this->get('http://acme.localhost/portal/plan')->assertOk()->assertSee('Check my plan')->assertSee('Acme WiFi');
    }

    // ── Viewing a plan ──────────────────────────────────────────────────────

    public function test_an_active_plan_shows_time_left_and_offers_renewal(): void
    {
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0002', minutesLeft: 90);
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0002']);

        $this->open($session)
            ->assertOk()
            ->assertSee('Daily Pass')
            ->assertSee('Active')
            ->assertSee('About 2 hours left')
            ->assertSee('Data allowance: 5GB')
            ->assertSee('Renew now')
            ->assertSee('UGX 2,000')
            ->assertSee('name="package_id" value="'.$this->package->id.'"', false)
            ->assertSee('value="0771234567"', false);
    }

    public function test_an_expired_plan_says_so_and_offers_to_buy_again(): void
    {
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0003', minutesLeft: -30);
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0003']);

        $this->open($session)->assertOk()->assertSee('Expired')->assertSee('Buy again');
    }

    public function test_a_package_that_is_no_longer_sold_offers_the_catalogue_instead(): void
    {
        $retired = $this->package($this->tenant, published: false);
        $session = $this->createSession($this->tenant, $retired, 'ACME-0004');
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0004']);

        $this->open($session)
            ->assertOk()
            ->assertSee('no longer offered')
            ->assertSee('See available packages')
            ->assertDontSee('Renew now');
    }

    public function test_a_link_only_works_for_the_business_it_belongs_to(): void
    {
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0005');
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0005']);

        $this->open($session, host: 'beta')->assertNotFound();
        $this->open($session, host: 'acme')->assertOk();
    }

    public function test_guessed_or_malformed_tokens_are_not_found(): void
    {
        $this->get('http://acme.localhost/portal/r/abcdefghijkl')->assertNotFound();   // well formed, unknown
        $this->get('http://acme.localhost/portal/r/short')->assertNotFound();
        $this->get('http://acme.localhost/portal/r/ABCDEFGHIJKL')->assertNotFound();    // upper case never issued
        $this->get('http://acme.localhost/portal/r/../../operator/dashboard')->assertNotFound();
    }

    public function test_the_page_is_not_indexed_by_search_engines(): void
    {
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0006');
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0006']);

        $this->open($session)->assertSee('<meta name="robots" content="noindex">', false);
    }

    // ── Renewing ────────────────────────────────────────────────────────────

    public function test_renewing_goes_through_the_normal_purchase_flow(): void
    {
        PlatformSetting::setSecret('mtn_subscription_key', 'k');
        PlatformSetting::setSecret('mtn_api_user', 'u');
        PlatformSetting::setSecret('mtn_api_key', 'a');
        config(['payments.simulation_enabled' => false]);
        Http::fake([
            'https://sandbox.momodeveloper.mtn.com/collection/token/' => Http::response(['access_token' => 't']),
            'https://sandbox.momodeveloper.mtn.com/collection/v1_0/requesttopay' => Http::response([], 202),
        ]);
        $session = $this->createSession($this->tenant, $this->package, 'ACME-0007');
        $this->post('http://acme.localhost/portal/plan', ['voucher_code' => 'ACME-0007']);
        $plan = $this->open($session)->assertOk();
        preg_match('/name="_idempotency_key" value="([A-Za-z0-9._:-]{16,128})"/', $plan->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'the renewal form must include a valid idempotency key');

        // exactly what the form on the page submits
        $this->post('http://acme.localhost/portal/purchase', [
            'package_id' => $this->package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $matches[1],
        ])
            ->assertSessionHasNoErrors();

        $sale = Transaction::withoutTenancy()->where('type', 'voucher_sale')->sole();
        $this->assertSame(['pending', 2000.0], [$sale->status, (float) $sale->amount]);
    }
}
