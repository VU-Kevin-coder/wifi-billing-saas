<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentGatewayConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
    }

    public function test_configuration_page_shows_only_the_new_payment_providers_and_keeps_old_secrets_private(): void
    {
        PlatformSetting::setSecret('flutterwave_secret_key', 'legacy-flutterwave-secret');
        PlatformSetting::setSecret('mtn_api_key', 'legacy-mtn-secret');
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('platform.configuration.index'))
            ->assertOk()
            ->assertSee('ObPay')
            ->assertSee('PawaPay')
            ->assertSee('Xente')
            ->assertDontSee('Pesapal')
            ->assertDontSee('Flutterwave')
            ->assertDontSee('legacy-flutterwave-secret')
            ->assertDontSee('legacy-mtn-secret');

        $this->assertSame('legacy-flutterwave-secret', PlatformSetting::getSecret('flutterwave_secret_key'));
        $this->assertSame('legacy-mtn-secret', PlatformSetting::getSecret('mtn_api_key'));
    }

    public function test_new_gateway_credentials_are_encrypted_and_blank_fields_preserve_saved_secrets(): void
    {
        $admin = $this->platformAdmin();
        $url = route('platform.configuration.payments.update');

        $this->actingAs($admin)->put($url, [
            'active_gateway' => 'obpay',
            'obpay_secret_key' => 'obpay-test-secret',
            'obpay_webhook_secret' => 'obpay-test-webhook',
            'pawapay_environment' => 'sandbox',
        ])->assertRedirect(route('platform.configuration.index'));

        $this->assertSame('obpay-test-secret', PlatformSetting::getSecret('payment.obpay.secret_key'));
        $this->assertSame('obpay-test-webhook', PlatformSetting::getSecret('payment.obpay.webhook_secret'));
        $this->assertSame('obpay', PlatformSetting::get('payment.active_gateway'));

        $this->actingAs($admin)->put($url, [
            'active_gateway' => '',
            'pawapay_environment' => 'sandbox',
        ])->assertRedirect(route('platform.configuration.index'));

        $this->assertSame('obpay-test-secret', PlatformSetting::getSecret('payment.obpay.secret_key'));
        $this->assertSame('obpay-test-webhook', PlatformSetting::getSecret('payment.obpay.webhook_secret'));
        $this->assertSame('', PlatformSetting::get('payment.active_gateway'));
    }

    public function test_legacy_payment_webhook_route_is_no_longer_registered(): void
    {
        $this->postJson('/api/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => [],
        ])->assertNotFound();
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        return $admin;
    }
}
