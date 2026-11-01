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
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertRedirect(route('operator.settings.index', ['tenant' => $tenant->slug]));

        $tenant->refresh();
        $this->assertSame('Acme Networks', $tenant->settings['brand_name']);
        $this->assertSame('#123abc', $tenant->settings['primary_color']);
        $this->assertSame('help@acme.test', $tenant->settings['support_email']);
        Storage::disk('public')->assertExists($tenant->settings['logo_path']);
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
            ],
        ]);

        $this->get('http://acme.localhost/portal')
            ->assertOk()
            ->assertSee('storage/logos/acme-logo.png')
            ->assertSee('Acme Networks logo');
    }
}
