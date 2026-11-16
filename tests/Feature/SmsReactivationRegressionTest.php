<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SmsReactivationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_network', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $this->actingAs($admin);
    }

    public function test_disabled_reactivation_keeps_only_the_same_bound_provider_on_both_interfaces(): void
    {
        Http::fake(['*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]])]);
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_credential_provider', 'africastalking');
        PlatformSetting::setSecret('sms_api_key', 'original-secret');
        PlatformSetting::set('sms_username', 'sandbox');
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Disable SMS delivery', 'settings' => ['sms.provider' => 'disabled']])->assertSessionHasNoErrors();
        $this->assertSame('africastalking', PlatformSetting::get('sms_credential_provider'));
        $this->assertFalse(SmsService::send('0700000000', 'test')['success']);
        Http::assertNothingSent();
        $this->get(route('platform.configuration.index'))->assertOk()->assertSee('Status: Disabled');
        $this->putJson(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => 'original-secret'])->assertUnprocessable();
        $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Switch SMS provider', 'settings' => ['sms.provider' => 'twilio', 'sms.api_key' => '']])->assertUnprocessable();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'africastalking', 'sms_environment' => 'sandbox', 'sms_api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('original-secret', PlatformSetting::getSecret('sms_api_key'));
        $this->assertTrue(SmsService::send('0700000000', 'test')['success']);
        Http::assertSent(fn ($r) => $r->hasHeader('apiKey', 'original-secret'));
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'disabled', 'sms_environment' => 'sandbox'])->assertSessionHasNoErrors();
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Reactivate SMS delivery', 'settings' => ['sms.provider' => 'africastalking', 'sms.api_key' => '']])->assertSessionHasNoErrors();
        $this->assertTrue(SmsService::send('0700000000', 'test')['success']);
        Http::assertSentCount(2);
        $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Disable and change secret', 'settings' => ['sms.provider' => 'disabled', 'sms.api_key' => 'new-secret']])->assertUnprocessable();
        $this->assertSame('africastalking', PlatformSetting::get('sms_provider'));
    }

    public function test_unbound_and_failed_status_are_visible_and_test_endpoint_uses_fake_requests(): void
    {
        Http::fake(['*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]])]);
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_username', 'sandbox');
        PlatformSetting::set('sms_api_key', 'legacy-secret');
        PlatformSetting::set('sms_status', 'Connected');
        $this->get(route('platform.configuration.index'))->assertOk()->assertSee('Status: Credential re-entry required')->assertDontSee('Status: Connected')->assertSee('Upgrade warning')->assertDontSee('legacy-secret');
        $this->get(route('platform.configuration.control', ['section' => 'sms']))->assertOk()->assertSee('SMS status: Credential re-entry required')->assertSee('Upgrade warning')->assertDontSee('legacy-secret');
        $this->post(route('platform.configuration.sms.test'), ['test_phone' => '0700000000'])->assertSessionHas('error');
        Http::assertNothingSent();
        $this->get(route('platform.configuration.index'))->assertOk()->assertSee('Status: Failed — Credential re-entry required');
        $this->get(route('platform.configuration.control', ['section' => 'sms']))->assertOk()->assertSee('SMS status: Failed — Credential re-entry required');
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'africastalking', 'sms_environment' => 'sandbox', 'sms_api_key' => 'legacy-secret'])->assertSessionHasNoErrors();
        $this->post(route('platform.configuration.sms.test'), ['test_phone' => '0700000000'])->assertSessionHas('status');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sandbox.africastalking.com/version1/messaging' && $request->hasHeader('apiKey', 'legacy-secret') && $request['to'] === '+256700000000');
        $this->get(route('platform.configuration.index'))->assertOk()->assertSee('Status: Connected')->assertDontSee('legacy-secret');
    }

    public function test_disabled_unbound_secret_cannot_be_reused_for_an_unknown_provider(): void
    {
        Http::fake();
        PlatformSetting::set('sms_provider', 'disabled');
        PlatformSetting::setSecret('sms_api_key', 'old-unknown');
        foreach (['', 'old-unknown'] as $secret) {
            $this->putJson(route('platform.configuration.sms.update'), ['sms_provider' => 'africastalking', 'sms_environment' => 'sandbox', 'sms_api_key' => $secret])->assertUnprocessable();
        }
        $this->assertSame('disabled', PlatformSetting::get('sms_provider'));
        $this->assertFalse(SmsService::send('0700000000', 'test')['success']);
        Http::assertNothingSent();
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Use verified SMS credential', 'settings' => ['sms.provider' => 'africastalking', 'sms.api_key' => 'new-secret']])->assertSessionHasNoErrors();
        $this->assertSame('africastalking', PlatformSetting::get('sms_credential_provider'));
        $this->assertSame('new-secret', PlatformSetting::getSecret('sms_api_key'));
    }
}
