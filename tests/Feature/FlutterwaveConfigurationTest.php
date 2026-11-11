<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FlutterwaveConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
    }

    public function test_flutterwave_secrets_are_not_rendered_in_platform_configuration(): void
    {
        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-value');
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->get(route('platform.configuration.index'))
            ->assertOk()
            ->assertSee('Flutterwave payout compatibility')
            ->assertDontSee('test-secret-value');
    }

    public function test_platform_currency_is_limited_to_supported_options(): void
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->put(route('platform.configuration.general.update'), [
                'platform_name' => 'Hamnet',
                'default_country' => 'Uganda',
                'currency' => 'EUR',
                'timezone' => 'Africa/Kampala',
                'date_format' => 'Y-m-d',
                'language' => 'en',
                'default_commission_rate' => 10,
            ])
            ->assertSessionHasErrors('currency');
    }

    public function test_flutterwave_configuration_saves_secrets_and_keeps_them_when_inputs_are_blank(): void
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->put(route('platform.configuration.flutterwave.update'), [
                'flutterwave_public_key' => 'FLWPUBK_TEST',
                'flutterwave_secret_key' => 'FLWSECK_TEST',
                'flutterwave_encryption_key' => 'FLWSECK_ENCRYPTION_TEST',
                'flutterwave_webhook_secret' => 'WEBHOOK_HASH_TEST',
                'flutterwave_environment' => 'sandbox',
            ])
            ->assertRedirect(route('platform.configuration.index'));

        $this->assertSame('FLWSECK_TEST', PlatformSetting::getSecret('flutterwave_secret_key'));
        $this->assertSame('FLWSECK_ENCRYPTION_TEST', PlatformSetting::getSecret('flutterwave_encryption_key'));
        $this->assertSame('WEBHOOK_HASH_TEST', PlatformSetting::getSecret('flutterwave_webhook_secret'));

        $this->actingAs($admin)
            ->put(route('platform.configuration.flutterwave.update'), [
                'flutterwave_environment' => 'production',
            ])
            ->assertRedirect(route('platform.configuration.index'));

        $this->assertSame('FLWSECK_TEST', PlatformSetting::getSecret('flutterwave_secret_key'));
        $this->assertSame('production', PlatformSetting::get('flutterwave_environment'));
    }

    public function test_mtn_and_airtel_secrets_are_encrypted_and_blank_values_keep_saved_credentials(): void
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->put(route('platform.configuration.mtn.update'), [
                'mtn_subscription_key' => 'mtn-subscription-secret',
                'mtn_api_user' => 'mtn-api-user',
                'mtn_api_key' => 'mtn-api-key-secret',
                'mtn_environment' => 'sandbox',
            ])
            ->assertRedirect(route('platform.configuration.index'));

        $this->actingAs($admin)
            ->put(route('platform.configuration.airtel.update'), [
                'airtel_client_id' => 'airtel-client-id',
                'airtel_client_secret' => 'airtel-client-secret',
                'airtel_environment' => 'production',
            ])
            ->assertRedirect(route('platform.configuration.index'));

        $this->actingAs($admin)
            ->put(route('platform.configuration.mtn.update'), [
                'mtn_environment' => 'production',
            ])
            ->assertRedirect(route('platform.configuration.index'));

        $this->assertSame('mtn-subscription-secret', PlatformSetting::getSecret('mtn_subscription_key'));
        $this->assertSame('mtn-api-user', PlatformSetting::getSecret('mtn_api_user'));
        $this->assertSame('mtn-api-key-secret', PlatformSetting::getSecret('mtn_api_key'));
        $this->assertSame('airtel-client-id', PlatformSetting::getSecret('airtel_client_id'));
        $this->assertSame('airtel-client-secret', PlatformSetting::getSecret('airtel_client_secret'));
        $this->assertSame('production', PlatformSetting::get('mtn_environment'));
        $this->assertSame('Configured (Untested)', PlatformSetting::get('mtn_status'));
    }

    public function test_mobile_money_credentials_are_not_rendered_in_the_configuration_page(): void
    {
        PlatformSetting::setSecret('mtn_subscription_key', 'hidden-mtn-secret');
        PlatformSetting::setSecret('mtn_api_user', 'hidden-mtn-user');
        PlatformSetting::setSecret('mtn_api_key', 'hidden-mtn-key');
        PlatformSetting::setSecret('airtel_client_id', 'hidden-airtel-client');
        PlatformSetting::setSecret('airtel_client_secret', 'hidden-airtel-secret');

        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->get(route('platform.configuration.index'))
            ->assertOk()
            ->assertSee('MTN MoMo collections')
            ->assertSee('Airtel Money collections')
            ->assertDontSee('hidden-mtn-secret')
            ->assertDontSee('hidden-mtn-user')
            ->assertDontSee('hidden-mtn-key')
            ->assertDontSee('hidden-airtel-client')
            ->assertDontSee('hidden-airtel-secret');
    }

    public function test_mobile_money_webhook_urls_can_be_overridden_and_default_to_named_routes(): void
    {
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        config([
            'services.mtn_momo.webhook_url' => 'https://callbacks.example.test/mtn',
            'services.airtel_money.webhook_url' => 'https://callbacks.example.test/airtel',
        ]);

        $this->actingAs($admin)
            ->get(route('platform.configuration.index'))
            ->assertOk()
            ->assertSee('https://callbacks.example.test/mtn')
            ->assertSee('https://callbacks.example.test/airtel');

        config([
            'services.mtn_momo.webhook_url' => null,
            'services.airtel_money.webhook_url' => null,
        ]);

        $this->get(route('platform.configuration.index'))
            ->assertOk()
            ->assertSee(route('api.webhooks.mtn'))
            ->assertSee(route('api.webhooks.airtel'));
    }
}
