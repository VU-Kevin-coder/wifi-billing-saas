<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_include_defaults_and_retain_saved_portal_values(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => [
                'primary_color' => '#123456',
                'portal_title' => 'Acme Guest Wi-Fi',
            ],
        ]);

        $this->assertSame('Acme Wi-Fi', $tenant->settings['brand_name']);
        $this->assertSame('acme.localhost:8000', $tenant->settings['domain']);
        $this->assertSame('#123456', $tenant->settings['primary_color']);
        $this->assertSame('Acme Guest Wi-Fi', $tenant->settings['portal_title']);
        $this->assertTrue($tenant->settings['show_packages']);
    }

    public function test_operator_can_view_and_update_general_settings(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        Storage::fake('public');

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings')
            ->assertOk()
            ->assertSee('Your ISP Branding')
            ->assertSee('Domain');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=portal')
            ->assertOk()
            ->assertSee('Portal Branding')
            ->assertSee('aria-current="page"', false);

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=hotspot')
            ->assertOk()
            ->assertSee('Hotspot Settings')
            ->assertSee('Customer ID Prefix')
            ->assertSee('name="voucher_length"', false);

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings/portal')
            ->assertRedirect('http://acme.localhost/operator/settings?tab=portal');

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/general', [
                'brand_name' => 'Acme Networks',
                'primary_color' => '#123abc',
                'support_phone' => '+256700111222',
                'support_email' => 'help@acme.test',
                'whatsapp_number' => '+256700333444',
                'domain' => 'acme.localhost:8000',
                'logo' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
            ])
            ->assertRedirect(route('operator.settings.index', ['tenant' => $tenant->slug]));

        $tenant->refresh();
        $this->assertSame('Acme Networks', $tenant->settings['brand_name']);
        $this->assertSame('#123abc', $tenant->settings['primary_color']);
        $this->assertSame('help@acme.test', $tenant->settings['support_email']);
        Storage::disk('public')->assertExists($tenant->settings['logo_path']);
    }

    public function test_operator_can_update_hotspot_settings_without_overwriting_other_settings(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => [
                'primary_color' => '#123456',
                'portal_title' => 'Acme Guest Wi-Fi',
            ],
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/hotspot', [
                'customer_id_prefix' => 'SE',
                'voucher_prefix' => 'AC',
                'voucher_length' => 10,
                'printed_voucher_length' => 6,
                'voucher_format' => 'numeric',
                'prune_inactive_days' => 0,
                'auto_reconnect_method' => 'ip_binding',
                'device_remember_days' => 60,
                'enable_agent_portal' => '1',
                'enable_agent_reselling' => '0',
                'enable_pppoe_self_registration' => '1',
                'block_tethering' => '0',
                'tethering_action' => 'log',
                'extend_gateway_fee' => '1',
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'hotspot',
            ]));

        $tenant->refresh();
        $settings = $tenant->settings;

        $this->assertSame('#123456', $settings['primary_color']);
        $this->assertSame('Acme Guest Wi-Fi', $settings['portal_title']);
        $this->assertSame('SE', $settings['customer_id_prefix']);
        $this->assertSame(10, $settings['voucher_length']);
        $this->assertSame(0, $settings['prune_inactive_days']);
        $this->assertSame('ip_binding', $settings['auto_reconnect_method']);
        $this->assertTrue($settings['enable_agent_portal']);
        $this->assertFalse($settings['enable_agent_reselling']);
        $this->assertFalse($settings['block_tethering']);
        $this->assertTrue($settings['extend_gateway_fee']);
    }

    public function test_operator_can_configure_communication_and_notification_settings(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => [
                'portal_title' => 'Acme Guest Wi-Fi',
                'sms_api_key' => 'sms-secret-value',
                'whatsapp_api_key' => 'whatsapp-secret-value',
                'whatsapp_webhook_secret' => 'webhook-secret-value',
            ],
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=sms')
            ->assertOk()
            ->assertSee('SMS Gateway Configuration')
            ->assertSee('name="sms_enabled"', false)
            ->assertSee('name="notify_expiry_sms_reminder"', false)
            ->assertSee('name="notify_expiry_reminder_mins"', false)
            ->assertSee('min="1" max="43200"', false)
            ->assertSee('name="template_expiry_reminder"', false)
            ->assertSee('@company_name, @package_name, @hours_left, @expiry_date, @portal_url, @support_number')
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('sms-secret-value');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=whatsapp')
            ->assertOk()
            ->assertSee('WhatsApp Configuration')
            ->assertDontSee('whatsapp-secret-value')
            ->assertDontSee('webhook-secret-value');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=notifications')
            ->assertOk()
            ->assertSee('Voucher and Payment Notifications')
            ->assertDontSee('name="notify_expiry_sms_reminder"', false);

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/sms', [
                'sms_enabled' => '1',
                'sms_gateway' => 'africastalking',
                'sms_sender_id' => 'ACME',
                'sms_api_key' => '',
                'notify_expiry_sms_reminder' => '1',
                'notify_expiry_reminder_mins' => '90',
                'template_expiry_reminder' => 'Hi @company_name: @package_name expires @expiry_date. @hours_left left. Renew @portal_url Help @support_number',
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'sms',
            ]));

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/whatsapp', [
                'whatsapp_enabled' => '1',
                'whatsapp_chatbot_enabled' => '1',
                'whatsapp_gateway' => 'soprasend',
                'whatsapp_device_id' => 'device-123',
                'whatsapp_api_key' => '',
                'whatsapp_webhook_secret' => '',
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'whatsapp',
            ]));

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/notifications', [
                'template_voucher' => 'Voucher: @password',
                'template_expiry' => 'Expired: @package_name',
                'notify_voucher_sms' => '1',
                'notify_receipt_sms' => '1',
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'notifications',
            ]));

        $settings = $tenant->fresh()->settings;
        $this->assertSame('Acme Guest Wi-Fi', $settings['portal_title']);
        $this->assertSame('africastalking', $settings['sms_gateway']);
        $this->assertTrue($settings['sms_enabled']);
        $this->assertSame('ACME', $settings['sms_sender_id']);
        $this->assertSame('sms-secret-value', $settings['sms_api_key']);
        $this->assertTrue($settings['whatsapp_enabled']);
        $this->assertTrue($settings['whatsapp_chatbot_enabled']);
        $this->assertSame('device-123', $settings['whatsapp_device_id']);
        $this->assertSame('whatsapp-secret-value', $settings['whatsapp_api_key']);
        $this->assertSame('webhook-secret-value', $settings['whatsapp_webhook_secret']);
        $this->assertTrue($settings['notify_voucher_sms']);
        $this->assertFalse($settings['notify_credentials_sms']);
        $this->assertTrue($settings['notify_receipt_sms']);
        $this->assertFalse($settings['notify_expiry_sms']);
        $this->assertTrue($settings['notify_expiry_sms_reminder']);
        $this->assertSame(90, $settings['notify_expiry_reminder_mins']);
        $this->assertSame('Hi @company_name: @package_name expires @expiry_date. @hours_left left. Renew @portal_url Help @support_number', $settings['template_expiry_reminder']);
        $this->assertSame('Voucher: @password', $settings['template_voucher']);

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/sms', [
                'sms_enabled' => '1',
                'sms_gateway' => 'africastalking',
                'sms_sender_id' => 'ACME',
                'notify_expiry_sms_reminder' => '0',
                'notify_expiry_reminder_mins' => '1440',
                'template_expiry_reminder' => $settings['template_expiry_reminder'],
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'sms',
            ]));

        $this->assertFalse($tenant->fresh()->settings['notify_expiry_sms_reminder']);
        $this->assertSame(1440, $tenant->fresh()->settings['notify_expiry_reminder_mins']);
    }

    public function test_operator_can_configure_workspace_two_factor_enforcement(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['portal_title' => 'Acme Guest Wi-Fi'],
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=security')
            ->assertOk()
            ->assertSee('Two-Factor Authentication')
            ->assertSee('Require two-factor authentication for all workspace users')
            ->assertSee('aria-current="page"', false)
            ->assertSee(route('profile.security.index'));

        $this->actingAs($operator)
            ->put('http://acme.localhost/operator/settings/security', [
                'enable_2fa' => '0',
                'require_2fa' => '1',
            ])
            ->assertRedirect(route('operator.settings.index', [
                'tenant' => $tenant->slug,
                'tab' => 'security',
            ]));

        $settings = $tenant->fresh()->settings;
        $this->assertTrue($settings['enable_2fa']);
        $this->assertTrue($settings['require_2fa']);
        $this->assertSame('Acme Guest Wi-Fi', $settings['portal_title']);

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/settings?tab=security')
            ->assertRedirect('http://acme.localhost/profile/security');
    }

    public function test_captive_portal_displays_the_saved_tenant_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/acme-logo.png', 'logo-content');

        Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => [
                'brand_name' => 'Acme Networks',
                'logo_path' => 'logos/acme-logo.png',
                'portal_title' => 'Acme Guest Wi-Fi',
                'portal_subtitle' => 'Fast, reliable guest access',
                'primary_color' => '#123456',
            ],
        ]);

        $this->get('http://acme.localhost/portal')
            ->assertOk()
            ->assertSee('storage/logos/acme-logo.png')
            ->assertSee('Acme Networks logo')
            ->assertSee('Acme Guest Wi-Fi')
            ->assertSee('Fast, reliable guest access')
            ->assertSee('--accent: #123456', false)
            ->assertSee('id="voucher-checking"', false)
            ->assertSee('Searching voucher records')
            ->assertSee('Voucher found, activating')
            ->assertSee('flex: 0 0 20px', false);
    }

    public function test_captive_portal_uses_the_dynamic_fallback_when_no_logo_is_configured(): void
    {
        Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['primary_color' => '#123456'],
        ]);

        $this->get('http://acme.localhost/portal')
            ->assertOk()
            ->assertSee('portal-logo-fallback', false)
            ->assertSee('Acme Wi-Fi')
            ->assertSee('--accent: #123456', false);
    }
}
