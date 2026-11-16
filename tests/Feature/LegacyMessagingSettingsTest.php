<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformMailService;
use App\Services\Policy\Policy;
use App\Services\SmsService;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegacyMessagingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');
        $this->actingAs($this->admin);
    }

    public function test_legacy_secrets_are_hidden_and_blank_preserves_them_before_and_after_rotation(): void
    {
        PlatformSetting::set('smtp_password', 'old-smtp-secret');
        PlatformSetting::set('sms_api_key', 'old-sms-secret');
        $this->get(route('platform.configuration.index'))->assertOk()
            ->assertDontSee('old-smtp-secret')->assertDontSee('old-sms-secret');

        $this->put(route('platform.configuration.email.update'), ['smtp_host' => 'smtp.example', 'smtp_password' => ''])->assertRedirect();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => ''])->assertRedirect();
        $this->assertSame('old-smtp-secret', PlatformSetting::getSecret('smtp_password'));
        $this->assertSame('old-sms-secret', PlatformSetting::getSecret('sms_api_key'));

        $this->put(route('platform.configuration.email.update'), ['smtp_host' => 'smtp.example', 'smtp_password' => 'new-smtp-secret'])->assertRedirect();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => 'new-sms-secret'])->assertRedirect();
        foreach (['smtp_password' => 'new-smtp-secret', 'sms_api_key' => 'new-sms-secret'] as $key => $secret) {
            $stored = PlatformSetting::where('key', $key)->value('value');
            $this->assertNotSame($secret, $stored);
            $this->assertSame($secret, PlatformSetting::getSecret($key));
            $this->get(route('platform.configuration.index'))->assertOk()->assertDontSee($secret)->assertDontSee($stored);
        }
        $this->put(route('platform.configuration.email.update'), ['smtp_password' => ''])->assertRedirect();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => ''])->assertRedirect();
        $this->assertSame('new-smtp-secret', PlatformSetting::getSecret('smtp_password'));
        $this->assertSame('new-sms-secret', PlatformSetting::getSecret('sms_api_key'));
        $audit = AuditLog::all()->toJson();
        foreach (['old-smtp-secret', 'old-sms-secret', 'new-smtp-secret', 'new-sms-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    public function test_rejected_inputs_and_validation_never_flash_secrets_or_persist_any_configuration(): void
    {
        PlatformSetting::set('smtp_host', 'saved.example');
        PlatformSetting::set('smtp_password', 'saved-password');
        PlatformSetting::set('sms_api_key', 'saved-key');
        $this->post(route('platform.configuration.email.test'), ['test_email' => 'test@example.com', 'smtp_host' => 'other.example', 'smtp_password' => 'submitted-password'])
            ->assertSessionHasErrors(['smtp_host', 'smtp_password']);
        $this->assertSame('saved.example', PlatformSetting::get('smtp_host'));
        $this->assertSame('saved-password', PlatformSetting::getSecret('smtp_password'));
        $this->assertNull(session()->getOldInput('smtp_password'));
        $this->post(route('platform.configuration.sms.test'), ['test_phone' => '0700000000', 'sms_api_key' => 'submitted-key', 'sms_provider' => 'twilio'])
            ->assertSessionHasErrors(['sms_provider', 'sms_api_key']);
        $this->assertSame('saved-key', PlatformSetting::getSecret('sms_api_key'));
        $this->assertNull(session()->getOldInput('sms_api_key'));
        $this->put(route('platform.configuration.email.update'), ['smtp_port' => 'bad', 'smtp_password' => 'validation-secret'])->assertSessionHasErrors('smtp_port');
        $this->assertNull(session()->getOldInput('smtp_password'));
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'bad', 'sms_environment' => 'sandbox', 'sms_api_key' => 'validation-key'])->assertSessionHasErrors('sms_provider');
        $this->assertNull(session()->getOldInput('sms_api_key'));
        $this->put(route('platform.configuration.control.update'), ['reason' => '', 'settings' => ['mail.password' => 'control-smtp-secret', 'sms.api_key' => 'control-sms-secret']])->assertSessionHasErrors('reason');
        $this->assertNull(session()->getOldInput('settings'));
    }

    public function test_failed_encryption_rolls_back_the_whole_save(): void
    {
        PlatformSetting::set('smtp_host', 'original.example');
        PlatformSetting::set('smtp_password', 'legacy-secret');
        Crypt::shouldReceive('encryptString')
            ->once()->andThrow(new \RuntimeException('Encryption unavailable'));
        $this->put(route('platform.configuration.email.update'), [
            'smtp_host' => 'replacement.example',
            'smtp_password' => 'replacement-secret',
        ])->assertStatus(500);
        $this->assertSame('original.example', PlatformSetting::get('smtp_host'));
        $this->assertSame('legacy-secret', PlatformSetting::where('key', 'smtp_password')->value('value'));
        $this->assertSame(0, AuditLog::where('action', 'platform.config.updated')->count());
    }

    public function test_mail_uses_plaintext_and_encrypted_values_and_test_endpoint_uses_saved_config(): void
    {
        PlatformSetting::set('smtp_host', 'mail.example');
        PlatformSetting::set('smtp_port', 2525);
        PlatformSetting::set('smtp_encryption', 'none');
        PlatformSetting::set('smtp_username', 'mail-user');
        PlatformSetting::set('smtp_password', 'legacy-mail');
        PlatformSetting::set('mail_from_address', 'from@example.com');
        Mail::shouldReceive('purge')->twice();
        $this->assertTrue(PlatformMailService::configureMailer());
        $this->assertSame('legacy-mail', config('mail.mailers.smtp.password'));
        PlatformSetting::setSecret('smtp_password', 'encrypted-mail');
        Mail::shouldReceive('html')->once()->andReturnUsing(function ($html, $callback) {
            $this->assertStringContainsString('mail.example', $html);
            $this->assertSame('encrypted-mail', config('mail.mailers.smtp.password'));
            $this->assertSame(2525, config('mail.mailers.smtp.port'));
            $this->assertNull(config('mail.mailers.smtp.encryption'));
            $message = \Mockery::mock();
            $message->shouldReceive('from')->once()->with('from@example.com', \Mockery::any())->andReturnSelf();
            $message->shouldReceive('to')->once()->with('receiver@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with(\Mockery::pattern('/SMTP Gateway Test/'))->andReturnSelf();
            $message->shouldReceive('text')->once()->andReturnSelf();
            $callback($message);
        });
        $this->post(route('platform.configuration.email.test'), ['test_email' => 'receiver@example.com'])->assertSessionHas('status');
        $this->assertSame('Connected', PlatformSetting::get('email_status'));
        $this->assertSame('encrypted-mail', PlatformSetting::getSecret('smtp_password'));
    }

    public function test_sms_uses_plaintext_and_encrypted_keys_for_both_gateways_without_network(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued']),
            '*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]]),
        ]);
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_username', 'sandbox');
        PlatformSetting::set('sms_environment', 'sandbox');
        PlatformSetting::set('sms_sender_id', 'BRAND');
        PlatformSetting::set('sms_api_key', 'legacy-key');
        $this->assertTrue(SmsService::send('0700000000', 'hello')['success']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.sandbox.africastalking.com/version1/messaging' && $r->hasHeader('apiKey', 'legacy-key') && $r['to'] === '+256700000000' && ! isset($r['from']));
        PlatformSetting::setSecret('sms_api_key', 'encrypted-key');
        PlatformSetting::set('sms_environment', 'production');
        $this->post(route('platform.configuration.sms.test'), ['test_phone' => '0700000000'])->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.africastalking.com/version1/messaging' && $r->hasHeader('apiKey', 'encrypted-key') && $r['from'] === 'BRAND');
        $this->assertSame('Connected', PlatformSetting::get('sms_status'));

        PlatformSetting::set('sms_provider', 'twilio');
        PlatformSetting::set('sms_username', 'AC123');
        PlatformSetting::set('sms_sender_id', '+12025550123');
        $this->assertTrue(SmsService::send('0700000000', 'hello')['success']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json' && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:encrypted-key')) && $r['From'] === '+12025550123');
        PlatformSetting::set('sms_api_key', 'legacy-twilio');
        $this->assertTrue(SmsService::send('0700000000', 'hello')['success']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json' && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:legacy-twilio')));
    }

    public function test_provider_error_and_exceptions_do_not_leak_secrets(): void
    {
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::setSecret('sms_api_key', 'secret-in-provider-response');
        Http::fake(['*' => Http::response(['errorMessage' => 'secret-in-provider-response'], 403)]);
        $result = SmsService::send('0700000000', 'hello');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('secret-in-provider-response', json_encode($result));
        Http::fake(function () {
            throw new \RuntimeException('secret-in-provider-response');
        });
        Log::shouldReceive('error')->once()->with('SMS dispatch failed.', ['provider' => 'africastalking']);
        $result = SmsService::send('0700000000', 'hello');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('secret-in-provider-response', json_encode($result));
        Mail::shouldReceive('purge')->zeroOrMoreTimes();
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('secret-in-provider-response'));
        $result = PlatformMailService::send('test@example.com', 'test', 'body');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('secret-in-provider-response', json_encode($result));
    }

    public function test_failed_connection_tests_record_failure_without_exposing_provider_messages(): void
    {
        PlatformSetting::set('smtp_host', 'mail.example');
        PlatformSetting::setSecret('smtp_password', 'mail-secret-value');
        Mail::shouldReceive('purge')->once();
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('mail-secret-value'));
        $this->post(route('platform.configuration.email.test'), ['test_email' => 'receiver@example.com'])->assertSessionHas('error');
        $this->assertStringNotContainsString('mail-secret-value', session('error'));
        $this->assertSame('Failed', PlatformSetting::get('email_status'));
        $this->assertSame('mail-secret-value', PlatformSetting::getSecret('smtp_password'));

        PlatformSetting::set('sms_provider', 'twilio');
        PlatformSetting::set('sms_username', 'AC123');
        PlatformSetting::set('sms_sender_id', '+12025550123');
        PlatformSetting::setSecret('sms_api_key', 'sms-secret-value');
        Http::fake(['*' => Http::response(['message' => 'sms-secret-value'], 403)]);
        $this->post(route('platform.configuration.sms.test'), ['test_phone' => '0700000000'])->assertSessionHas('error');
        $this->assertStringNotContainsString('sms-secret-value', session('error'));
        $this->assertSame('Failed', PlatformSetting::get('sms_status'));
        $this->assertSame('sms-secret-value', PlatformSetting::getSecret('sms_api_key'));
    }

    public function test_sensitive_routes_require_recent_mfa_when_policy_enabled(): void
    {
        config(['security.step_up_enabled' => true, 'security.platform_admin_mfa_required' => false]);
        Policy::set('security.step_up_enabled', true);
        MFA::enableMethod($this->admin, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);
        foreach ([['put', 'platform.configuration.email.update', ['smtp_password' => 'blocked']], ['put', 'platform.configuration.sms.update', ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => 'blocked']], ['post', 'platform.configuration.email.test', ['test_email' => 'test@example.com']], ['post', 'platform.configuration.sms.test', ['test_phone' => '0700000000']]] as [$method, $route, $data]) {
            $this->withSession(['mfa' => ['verified' => true, 'verified_at' => now()->subMinutes(30)->getTimestamp()]])->$method(route($route), $data)->assertRedirect(route('mfa.step-up'));
        }
        $this->assertNull(PlatformSetting::getSecret('smtp_password'));
        $this->assertNull(PlatformSetting::getSecret('sms_api_key'));
        $this->withSession(['mfa' => ['verified' => true, 'verified_at' => now()->getTimestamp()]])->put(route('platform.configuration.email.update'), ['smtp_password' => 'allowed'])->assertRedirect(route('platform.configuration.index'));
        $this->assertSame('allowed', PlatformSetting::getSecret('smtp_password'));
    }
}
