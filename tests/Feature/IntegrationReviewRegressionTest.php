<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\PolicySetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('platform_admin', 'web');
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $this->actingAs($admin);
    }

    public function test_provider_changes_require_different_key_in_both_interfaces_and_dispatch_is_bound(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM123'])]);
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_username', 'sandbox');
        PlatformSetting::set('sms_sender_id', 'OLD');
        PlatformSetting::setSecret('sms_api_key', 'old-secret');
        PlatformSetting::set('sms_status', 'Connected');
        $this->assertFalse(SmsService::send('0700000000', 'test')['success']);
        Http::assertNothingSent();
        foreach (['', 'old-secret', ' old-secret '] as $key) {
            $this->putJson(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_api_key' => $key])->assertUnprocessable();
            $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Change SMS provider', 'settings' => ['sms.provider' => 'twilio', 'sms.api_key' => $key]])->assertUnprocessable();
        }
        $this->assertSame('africastalking', PlatformSetting::get('sms_provider'));
        $this->assertSame('Connected', PlatformSetting::get('sms_status'));
        $this->assertFalse(SmsService::sendViaTwilio('+256700000000', 'test')['success']);
        Http::assertNothingSent();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'twilio', 'sms_environment' => 'sandbox', 'sms_username' => 'AC123', 'sms_sender_id' => '+12025550123', 'sms_api_key' => 'new-secret'])->assertSessionHasNoErrors();
        $this->assertSame('Configured (Untested)', PlatformSetting::get('sms_status'));
        $this->assertTrue(SmsService::send('0700000000', 'test')['success']);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.twilio.com/') && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:new-secret')));
        $this->assertStringNotContainsString('old-secret', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('new-secret', AuditLog::all()->toJson());
        PlatformSetting::set('sms_provider', 'africastalking'); // Simulate an inconsistent legacy write.
        $this->assertFalse(SmsService::send('0700000000', 'test')['success']);
        Http::assertSentCount(1);
    }

    public function test_unbound_legacy_key_cannot_send_until_explicitly_reentered(): void
    {
        Http::fake(['*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]])]);
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_api_key', 'legacy-key');
        PlatformSetting::set('sms_status', 'Connected');
        $this->get(route('platform.configuration.index'))->assertOk()->assertDontSee('Status: Connected');
        $this->assertFalse(SmsService::send('0700000000', 'test')['success']);
        Http::assertNothingSent();
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'africastalking', 'sms_environment' => 'sandbox', 'sms_api_key' => 'legacy-key'])->assertSessionHasNoErrors();
        $this->assertSame('africastalking', PlatformSetting::get('sms_credential_provider'));
        $this->assertSame('Configured (Untested)', PlatformSetting::get('sms_status'));
        $this->assertTrue(SmsService::send('0700000000', 'test')['success']);
        Http::assertSent(fn ($request) => $request->hasHeader('apiKey', 'legacy-key'));
    }

    public function test_connection_status_resets_on_config_changes_in_both_interfaces(): void
    {
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_status', 'Connected');
        $this->put(route('platform.configuration.sms.update'), ['sms_provider' => 'africastalking', 'sms_environment' => 'production', 'sms_api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Configured (Untested)', PlatformSetting::get('sms_status'));
        PlatformSetting::set('sms_status', 'Connected');
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Change SMS sender', 'settings' => ['sms.sender' => 'NEW']])->assertSessionHasNoErrors();
        $this->assertSame('Configured (Untested)', PlatformSetting::get('sms_status'));
        PlatformSetting::set('email_status', 'Connected');
        $this->put(route('platform.configuration.email.update'), ['smtp_username' => 'new-user'])->assertSessionHasNoErrors();
        $this->assertSame('Configured (Untested)', PlatformSetting::get('email_status'));
    }

    public function test_missing_lifecycle_default_is_visible_editable_enforced_and_audited(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'suspended', 'kyc_status' => 'verified', 'subscription_status' => 'active']);
        $this->assertTrue(Policy::denies($tenant, 'portal_sell'));
        $page = $this->get(route('platform.policies.index'))->assertOk()->assertSee('Blocked (default; not saved)')->assertDontSee('No policy: always allowed')->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($page);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@type="checkbox" and @name="policies[lifecycle.suspended.portal_sell]" and not(@checked)]')->length);
        $this->put(route('platform.policies.update'), ['reason' => 'Allow suspended portal sales', 'policies' => ['lifecycle.suspended.portal_sell' => '1']])->assertSessionHasNoErrors();
        $this->assertFalse(Policy::denies($tenant, 'portal_sell'));
        $audit = AuditLog::where('action', 'policy.lifecycle.updated')->sole();
        $this->assertNull($audit->meta['changes']['lifecycle.suspended.portal_sell']['old']);
        $this->assertTrue($audit->meta['changes']['lifecycle.suspended.portal_sell']['new']);
        Policy::set('lifecycle.suspended.portal_sell', 'bad');
        $this->get(route('platform.policies.index'))->assertOk()->assertSee('invalid stored value')->assertSee('Repair Suspended: Sell vouchers on the portal');
        $this->put(route('platform.policies.update'), ['reason' => 'Repair invalid policy', 'policies' => ['lifecycle.suspended.portal_sell' => '0']])->assertSessionHasNoErrors();
        $this->assertTrue(Policy::denies($tenant, 'portal_sell'));
        $this->assertSame(false, PolicySetting::where('key', 'lifecycle.suspended.portal_sell')->firstOrFail()->value);
    }
}
