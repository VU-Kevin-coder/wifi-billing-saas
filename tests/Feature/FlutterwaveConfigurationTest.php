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
            ->assertSee('Flutterwave configuration')
            ->assertDontSee('test-secret-value');
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
}
