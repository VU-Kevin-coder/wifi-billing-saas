<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Payments\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.platform_admin_mfa_required' => false,
            'security.step_up_enabled' => false,
        ]);
        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_network', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');
    }

    public function test_control_settings_exposes_only_the_supported_checkout_gateways(): void
    {
        PlatformSetting::setSecret('payment.pawapay.api_token', 'gateway-secret');

        $response = $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'payments']))
            ->assertOk()
            ->assertSee('OBPay')
            ->assertSee('PawaPay')
            ->assertSee('ioTec')
            ->assertDontSee('gateway-secret')
            ->assertDontSee('Flutterwave')
            ->assertDontSee('Pesapal')
            ->assertDontSee('Xente');

        $html = $response->getContent();
        $this->assertStringContainsString('data-payment-gateway-selector', $html);
        $this->assertStringContainsString('data-payment-gateway-group="obpay"', $html);
        $this->assertStringContainsString('data-payment-gateway-group="pawapay"', $html);
        $this->assertStringContainsString('data-payment-gateway-group="iotec"', $html);
        $this->assertStringContainsString('data-required-when="obpay"', $html);
    }

    public function test_obpay_environment_configuration_and_callback_url_are_visible_without_exposing_secrets(): void
    {
        config([
            'services.obpay.base_url' => 'https://obpay.online/api/public/v1',
            'services.obpay.secret_key' => 'env-obpay-secret',
            'services.obpay.webhook_secret' => 'env-obpay-webhook-secret',
            'services.obpay.callback_url' => 'https://billing.example.test/api/webhooks/obpay',
        ]);

        $this->assertTrue(PaymentGatewaySettings::configured('obpay'));

        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'payments']))
            ->assertOk()
            ->assertSee('https://obpay.online/api/public/v1')
            ->assertSee('https://billing.example.test/api/webhooks/obpay')
            ->assertSee('Saved — type to replace')
            ->assertDontSee('env-obpay-secret')
            ->assertDontSee('env-obpay-webhook-secret');

        $this->assertDatabaseMissing('platform_settings', ['key' => 'payment.obpay.secret_key']);
        $this->assertDatabaseMissing('platform_settings', ['key' => 'payment.obpay.webhook_secret']);
    }

    public function test_obpay_rejects_an_insecure_api_base_url(): void
    {
        config([
            'services.obpay.base_url' => 'http://obpay.online/api/public/v1',
            'services.obpay.secret_key' => 'env-obpay-secret',
            'services.obpay.webhook_secret' => 'env-obpay-webhook-secret',
            'services.obpay.callback_url' => 'https://billing.example.test/api/webhooks/obpay',
        ]);

        $this->assertFalse(PaymentGatewaySettings::configured('obpay'));
    }

    public function test_admin_can_set_the_obpay_callback_url_in_the_payments_ui(): void
    {
        $callbackUrl = 'https://billing.example.test/api/webhooks/obpay';

        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Set OBPay callback endpoint',
                'settings' => ['payment.obpay.callback_url' => $callbackUrl],
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'payments']))
            ->assertSessionHasNoErrors();

        $this->assertSame($callbackUrl, PlatformSetting::get('payment.obpay.callback_url'));
        $this->assertSame($callbackUrl, PaymentGatewaySettings::obpayCallbackUrl());
    }

    public function test_enabling_obpay_reports_the_specific_missing_webhook_secret(): void
    {
        config([
            'services.obpay.secret_key' => null,
            'services.obpay.webhook_secret' => null,
            'services.obpay.callback_url' => 'https://billing.example.test/api/webhooks/obpay',
        ]);
        PlatformSetting::setSecret('payment.obpay.secret_key', 'configured-api-secret');

        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Enable OBPay checkout',
                'settings' => ['payment.active_gateway' => 'obpay'],
            ])
            ->assertSessionHasErrors([
                'settings.payment.active_gateway' => 'OBPay cannot be enabled. Check: webhook signing secret (Payments UI or OBPAY_WEBHOOK_SECRET).',
            ]);
    }

    public function test_admin_can_save_credentials_and_select_one_configured_checkout_gateway(): void
    {
        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Enable PawaPay checkout',
                'settings' => [
                    'payment.active_gateway' => 'pawapay',
                    'payment.pawapay.api_token' => 'pawa-test-secret',
                    'payment.pawapay.environment' => 'sandbox',
                ],
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'payments']))
            ->assertSessionHasNoErrors();

        $this->assertSame('pawapay', PlatformSetting::get('payment.active_gateway'));
        $this->assertSame('pawa-test-secret', PlatformSetting::getSecret('payment.pawapay.api_token'));
        $this->assertNotSame('pawa-test-secret', PlatformSetting::where('key', 'payment.pawapay.api_token')->value('value'));
    }

    public function test_admin_can_select_obpay_without_configuring_other_gateways(): void
    {
        config([
            'services.obpay.base_url' => 'https://obpay.online/api/public/v1',
            'services.obpay.secret_key' => null,
            'services.obpay.webhook_secret' => null,
        ]);

        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Enable OBPay checkout',
                'settings' => [
                    'payment.active_gateway' => 'obpay',
                    'payment.obpay.secret_key' => 'obpay-test-api-secret',
                    'payment.obpay.webhook_secret' => 'obpay-test-signing-secret',
                ],
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'payments']))
            ->assertSessionHasNoErrors();

        $this->assertSame('obpay', PlatformSetting::get('payment.active_gateway'));
        $this->assertSame('obpay-test-api-secret', PlatformSetting::getSecret('payment.obpay.secret_key'));
        $this->assertSame('obpay-test-signing-secret', PlatformSetting::getSecret('payment.obpay.webhook_secret'));
        $this->assertNull(PlatformSetting::getSecret('payment.pawapay.api_token'));
        $this->assertNull(PlatformSetting::getSecret('payment.iotec.client_id'));
    }

    public function test_payment_ui_only_requires_fields_for_the_selected_gateway(): void
    {
        config([
            'services.obpay.secret_key' => null,
            'services.obpay.webhook_secret' => null,
        ]);
        PlatformSetting::set('payment.active_gateway', 'obpay');

        $html = $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'payments']))
            ->assertOk()
            ->getContent();

        preg_match('/<section[^>]*data-payment-gateway-group="obpay"[^>]*>/', $html, $obpayGroup);
        preg_match('/<section[^>]*data-payment-gateway-group="pawapay"[^>]*>/', $html, $pawaPayGroup);
        $this->assertNotEmpty($obpayGroup);
        $this->assertNotEmpty($pawaPayGroup);
        $this->assertStringNotContainsString('hidden', $obpayGroup[0]);
        $this->assertStringContainsString('hidden', $pawaPayGroup[0]);
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*name="settings\[payment\.obpay\.secret_key\]"[^>]*\srequired(?:\s|>)/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b[^>]*name="settings\[payment\.pawapay\.api_token\]"[^>]*\srequired(?:\s|>)/',
            $html,
        );
    }

    public function test_saving_a_gateway_credential_does_not_require_other_active_gateway_credentials(): void
    {
        PlatformSetting::set('payment.active_gateway', 'pawapay');

        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Save OBPay API key',
                'settings' => ['payment.obpay.secret_key' => 'obpay-test-api-secret'],
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'payments']))
            ->assertSessionHasNoErrors();

        $this->assertSame('pawapay', PlatformSetting::get('payment.active_gateway'));
        $this->assertSame('obpay-test-api-secret', PlatformSetting::getSecret('payment.obpay.secret_key'));
        $this->assertFalse(PaymentGatewaySettings::configured('pawapay'));
    }

    public function test_admin_cannot_activate_a_gateway_without_all_required_credentials(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Enable incomplete ioTec',
                'settings' => ['payment.active_gateway' => 'iotec'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.payment.active_gateway')
            ->assertSee(
                'ioTec cannot be enabled. Check: OAuth client ID (Payments UI); OAuth client secret (Payments UI); wallet ID (Payments UI); callback bearer secret (Payments UI).',
            );

        $this->assertNull(PlatformSetting::get('payment.active_gateway'));
    }
}
