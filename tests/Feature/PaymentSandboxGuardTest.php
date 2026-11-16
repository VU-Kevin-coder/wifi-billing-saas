<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\PaymentSandboxGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSandboxGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_accepts_only_sandbox_settings_and_expected_https_hosts(): void
    {
        config(['payments.sandbox_only' => true]);
        foreach ([
            ['mtn', 'mtn_environment', 'https://sandbox.momodeveloper.mtn.com'],
            ['airtel', 'airtel_environment', 'https://openapiuat.airtel.africa'],
            ['pesapal', 'pesapal_environment', 'https://cybqa.pesapal.com/pesapalv3/api'],
        ] as [$provider, $setting, $url]) {
            PlatformSetting::set($setting, 'sandbox');
            PaymentSandboxGuard::check($provider, $url);
            foreach (['http://'.parse_url($url, PHP_URL_HOST), 'https://example.test', 'https://user@'.parse_url($url, PHP_URL_HOST), 'https://'.parse_url($url, PHP_URL_HOST).'.evil.test', 'https://'.parse_url($url, PHP_URL_HOST).':8443', 'https://'.parse_url($url, PHP_URL_HOST).'@evil.test'] as $badUrl) {
                try {
                    PaymentSandboxGuard::check($provider, $badUrl);
                    $this->fail('Sandbox guard accepted '.$badUrl);
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('rejected', $e->getMessage());
                }
            }
            PlatformSetting::set($setting, 'production');
            try {
                PaymentSandboxGuard::check($provider, $url);
                $this->fail('Sandbox guard accepted production settings for '.$provider);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rejected', $e->getMessage());
            }
        }
    }
}
