<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
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

        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'payments']))
            ->assertOk()
            ->assertSee('OBPay')
            ->assertSee('PawaPay')
            ->assertSee('ioTec')
            ->assertDontSee('gateway-secret')
            ->assertDontSee('Flutterwave')
            ->assertDontSee('Pesapal')
            ->assertDontSee('Xente');
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

    public function test_admin_cannot_activate_a_gateway_without_all_required_credentials(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('platform.configuration.control.update'), [
                'section' => 'payments',
                'reason' => 'Enable incomplete ioTec',
                'settings' => ['payment.active_gateway' => 'iotec'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.payment.active_gateway');

        $this->assertNull(PlatformSetting::get('payment.active_gateway'));
    }
}
