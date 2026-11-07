<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\PlatformMailService;
use App\Services\SmsService;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function index()
    {
        $settings = [
            // General
            'platform_name' => PlatformSetting::get('platform_name', 'Hamnet'),
            'default_country' => PlatformSetting::get('default_country', 'Uganda'),
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'timezone' => PlatformSetting::get('timezone', 'Africa/Kampala'),
            'date_format' => PlatformSetting::get('date_format', 'DD MMM YYYY'),
            'language' => PlatformSetting::get('language', 'English (Uganda)'),
            'default_commission_rate' => PlatformSetting::get('default_commission_rate', 5.00),

            // Security
            'notify_email_alerts' => PlatformSetting::get('notify_email_alerts', true),
            'notify_sms_alerts' => PlatformSetting::get('notify_sms_alerts', true),
            'enforce_2fa' => PlatformSetting::get('enforce_2fa', false),
            'session_timeout' => PlatformSetting::get('session_timeout', 30),

            // Pesapal
            'pesapal_consumer_key' => PlatformSetting::get('pesapal_consumer_key'),
            'pesapal_consumer_secret' => PlatformSetting::getSecret('pesapal_consumer_secret') ?: PlatformSetting::get('pesapal_consumer_secret'),
            'pesapal_environment' => PlatformSetting::get('pesapal_environment', 'sandbox'),
            'pesapal_status' => PlatformSetting::get('pesapal_status', 'Not Configured'),
            'pesapal_ipn_id' => PlatformSetting::get('pesapal_ipn_id'),

            // Flutterwave
            'flutterwave_public_key' => PlatformSetting::get(
                'flutterwave_public_key',
                config('services.flutterwave.public_key')
            ),
            'flutterwave_public_key_configured' => (bool) PlatformSetting::get(
                'flutterwave_public_key',
                config('services.flutterwave.public_key')
            ),
            'flutterwave_secret_key_configured' => (bool) PlatformSetting::getSecret(
                'flutterwave_secret_key',
                config('services.flutterwave.secret_key')
            ),
            'flutterwave_encryption_key_configured' => (bool) PlatformSetting::getSecret(
                'flutterwave_encryption_key',
                config('services.flutterwave.encryption_key')
            ),
            'flutterwave_webhook_secret_configured' => (bool) PlatformSetting::getSecret(
                'flutterwave_webhook_secret',
                config('services.flutterwave.webhook_secret')
            ),
            'flutterwave_environment' => PlatformSetting::get(
                'flutterwave_environment',
                config('services.flutterwave.environment', 'sandbox')
            ),
            'flutterwave_status' => PlatformSetting::get('flutterwave_status', 'Not Configured'),

            // Mobile money collections
            'mtn_subscription_key_configured' => (bool) PlatformSetting::getSecret('mtn_subscription_key'),
            'mtn_api_user_configured' => (bool) PlatformSetting::getSecret('mtn_api_user'),
            'mtn_api_key_configured' => (bool) PlatformSetting::getSecret('mtn_api_key'),
            'mtn_environment' => PlatformSetting::get('mtn_environment', 'sandbox'),
            'mtn_webhook_url' => config('services.mtn_momo.webhook_url') ?: route('api.webhooks.mtn'),
            'mtn_status' => PlatformSetting::get('mtn_status', 'Not Configured'),
            'airtel_client_id_configured' => (bool) PlatformSetting::getSecret('airtel_client_id'),
            'airtel_client_secret_configured' => (bool) PlatformSetting::getSecret('airtel_client_secret'),
            'airtel_environment' => PlatformSetting::get('airtel_environment', 'sandbox'),
            'airtel_webhook_url' => config('services.airtel_money.webhook_url') ?: route('api.webhooks.airtel'),
            'airtel_status' => PlatformSetting::get('airtel_status', 'Not Configured'),

            // Email (SMTP)
            'smtp_host' => PlatformSetting::get('smtp_host'),
            'smtp_port' => PlatformSetting::get('smtp_port', 587),
            'smtp_encryption' => PlatformSetting::get('smtp_encryption', 'tls'),
            'smtp_username' => PlatformSetting::get('smtp_username'),
            'smtp_password' => PlatformSetting::get('smtp_password'),
            'mail_from_address' => PlatformSetting::get('mail_from_address', 'notifications@hamnet.com'),
            'mail_from_name' => PlatformSetting::get('mail_from_name', 'Hamnet Platform'),
            'email_status' => PlatformSetting::get('email_status', 'Not Tested'),

            // SMS Gateway
            'sms_provider' => PlatformSetting::get('sms_provider', 'africastalking'),
            'sms_username' => PlatformSetting::get('sms_username', 'sandbox'),
            'sms_api_key' => PlatformSetting::get('sms_api_key'),
            'sms_sender_id' => PlatformSetting::get('sms_sender_id', 'HAMNET'),
            'sms_environment' => PlatformSetting::get('sms_environment', 'sandbox'),
            'sms_status' => PlatformSetting::get('sms_status', 'Not Tested'),
        ];

        return view('platform.configuration.index', compact('settings'));
    }

    public function updateGeneral(Request $request)
    {
        $validated = $request->validate([
            'platform_name' => 'required|string',
            'default_country' => 'required|string',
            'currency' => 'required|string',
            'timezone' => 'required|string',
            'date_format' => 'required|string',
            'language' => 'required|string',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'general']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'General settings updated successfully.');
    }

    public function updateSecurity(Request $request)
    {
        PlatformSetting::set('notify_email_alerts', $request->boolean('notify_email_alerts'));
        PlatformSetting::set('notify_sms_alerts', $request->boolean('notify_sms_alerts'));
        PlatformSetting::set('enforce_2fa', $request->boolean('enforce_2fa'));
        PlatformSetting::set('session_timeout', $request->input('session_timeout', 30));

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'security']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Security settings updated successfully.');
    }

    public function updatePesapal(Request $request)
    {
        $validated = $request->validate([
            'pesapal_consumer_key' => 'nullable|string|max:255',
            'pesapal_consumer_secret' => 'nullable|string|max:255',
            'pesapal_environment' => 'required|in:sandbox,production',
            'pesapal_ipn_id' => 'nullable|string|max:255',
        ]);

        foreach ($validated as $key => $value) {
            if ($key === 'pesapal_consumer_secret') {
                if ($request->filled($key)) {
                    PlatformSetting::setSecret($key, $value);
                }
            } else {
                PlatformSetting::set($key, $value);
            }
        }

        $hasSecret = filled(PlatformSetting::getSecret('pesapal_consumer_secret')) || filled(PlatformSetting::get('pesapal_consumer_secret'));
        $isConfigured = filled(PlatformSetting::get('pesapal_consumer_key')) && $hasSecret;
        PlatformSetting::set('pesapal_status', $isConfigured ? 'Configured (Untested)' : 'Not Configured');

        \App\Models\AuditLog::record('platform.config.updated', null, [
            'section' => 'pesapal',
            'environment' => $validated['pesapal_environment'],
            'credentials_changed' => array_values(array_filter(
                ['pesapal_consumer_key', 'pesapal_consumer_secret', 'pesapal_ipn_id'],
                fn (string $key) => $request->filled($key)
            )),
        ]);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Pesapal configuration saved.');
    }

    public function updateFlutterwave(Request $request)
    {
        $validated = $request->validate([
            'flutterwave_public_key' => 'nullable|string|max:255',
            'flutterwave_secret_key' => 'nullable|string|max:255',
            'flutterwave_encryption_key' => 'nullable|string|max:255',
            'flutterwave_webhook_secret' => 'nullable|string|max:255',
            'flutterwave_environment' => 'required|in:sandbox,production',
        ]);

        if ($request->filled('flutterwave_public_key')) {
            PlatformSetting::set('flutterwave_public_key', $validated['flutterwave_public_key']);
        }

        foreach ([
            'flutterwave_secret_key',
            'flutterwave_encryption_key',
            'flutterwave_webhook_secret',
        ] as $key) {
            if ($request->filled($key)) {
                PlatformSetting::setSecret($key, $validated[$key]);
            }
        }

        PlatformSetting::set('flutterwave_environment', $validated['flutterwave_environment']);
        $configured = PlatformSetting::getSecret(
            'flutterwave_secret_key',
            config('services.flutterwave.secret_key')
        );
        PlatformSetting::set('flutterwave_status', $configured ? 'Configured (Untested)' : 'Not Configured');

        \App\Models\AuditLog::record('platform.config.updated', null, [
            'section' => 'flutterwave',
            'environment' => $validated['flutterwave_environment'],
            // Field names only; secret values are never written to the audit log.
            'credentials_changed' => array_values(array_filter(
                ['flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_encryption_key', 'flutterwave_webhook_secret'],
                fn (string $key) => $request->filled($key)
            )),
        ]);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Flutterwave configuration saved.');
    }

    public function updateMtn(Request $request)
    {
        $validated = $request->validate([
            'mtn_subscription_key' => 'nullable|string|max:255',
            'mtn_api_user' => 'nullable|string|max:255',
            'mtn_api_key' => 'nullable|string|max:255',
            'mtn_environment' => 'required|in:sandbox,production',
        ]);

        foreach (['mtn_subscription_key', 'mtn_api_user', 'mtn_api_key'] as $key) {
            if ($request->filled($key)) {
                PlatformSetting::setSecret($key, $validated[$key]);
            }
        }

        PlatformSetting::set('mtn_environment', $validated['mtn_environment']);
        $configured = PlatformSetting::getSecret('mtn_subscription_key')
            && PlatformSetting::getSecret('mtn_api_user')
            && PlatformSetting::getSecret('mtn_api_key');
        PlatformSetting::set('mtn_status', $configured ? 'Configured (Untested)' : 'Not Configured');

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'mtn']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'MTN MoMo configuration saved.');
    }

    public function updateAirtel(Request $request)
    {
        $validated = $request->validate([
            'airtel_client_id' => 'nullable|string|max:255',
            'airtel_client_secret' => 'nullable|string|max:255',
            'airtel_environment' => 'required|in:sandbox,production',
        ]);

        foreach (['airtel_client_id', 'airtel_client_secret'] as $key) {
            if ($request->filled($key)) {
                PlatformSetting::setSecret($key, $validated[$key]);
            }
        }

        PlatformSetting::set('airtel_environment', $validated['airtel_environment']);
        $configured = PlatformSetting::getSecret('airtel_client_id')
            && PlatformSetting::getSecret('airtel_client_secret');
        PlatformSetting::set('airtel_status', $configured ? 'Configured (Untested)' : 'Not Configured');

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'airtel']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Airtel Money configuration saved.');
    }

    public function updateEmail(Request $request)
    {
        $validated = $request->validate([
            'smtp_host' => 'nullable|string',
            'smtp_port' => 'nullable|integer',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'smtp_username' => 'nullable|string',
            'smtp_password' => 'nullable|string',
            'mail_from_address' => 'nullable|email',
            'mail_from_name' => 'nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        if (! empty($validated['smtp_host'])) {
            PlatformSetting::set('email_status', 'Configured (Untested)');
        }

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'email']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Email gateway configuration saved.');
    }

    public function testEmail(Request $request)
    {
        $validated = $request->validate([
            'test_email' => 'required|email',
            'smtp_host' => 'nullable|string',
            'smtp_port' => 'nullable|integer',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'smtp_username' => 'nullable|string',
            'smtp_password' => 'nullable|string',
            'mail_from_address' => 'nullable|email',
            'mail_from_name' => 'nullable|string',
        ]);

        // If credentials were provided in the test form, persist them first
        $fields = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name'];
        foreach ($fields as $field) {
            if ($request->filled($field)) {
                PlatformSetting::set($field, $request->input($field));
            }
        }

        $result = PlatformMailService::testConnection($validated['test_email']);

        if ($result['success']) {
            return redirect()->route('platform.configuration.index')
                ->with('status', "✅ Test email successfully sent to {$validated['test_email']}! SMTP gateway is verified.")
                ->with('overlay', 'email');
        }

        return redirect()->route('platform.configuration.index')
            ->with('error', "❌ SMTP Connection failed: {$result['message']}")
            ->with('overlay', 'email');
    }

    public function updateSms(Request $request)
    {
        $validated = $request->validate([
            'sms_provider' => 'required|in:africastalking,twilio',
            'sms_username' => 'nullable|string',
            'sms_api_key' => 'nullable|string',
            'sms_sender_id' => 'nullable|string',
            'sms_environment' => 'required|in:sandbox,production',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        if (! empty($validated['sms_api_key'])) {
            PlatformSetting::set('sms_status', 'Configured (Untested)');
        }

        \App\Models\AuditLog::record('platform.config.updated', null, ['section' => 'sms']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'SMS gateway configuration saved.');
    }

    public function testSms(Request $request)
    {
        $validated = $request->validate([
            'test_phone' => 'required|string|min:9',
            'sms_provider' => 'nullable|in:africastalking,twilio',
            'sms_username' => 'nullable|string',
            'sms_api_key' => 'nullable|string',
            'sms_sender_id' => 'nullable|string',
            'sms_environment' => 'nullable|in:sandbox,production',
        ]);

        // If credentials were provided in the test form, persist them first
        $fields = ['sms_provider', 'sms_username', 'sms_api_key', 'sms_sender_id', 'sms_environment'];
        foreach ($fields as $field) {
            if ($request->filled($field)) {
                PlatformSetting::set($field, $request->input($field));
            }
        }

        $result = SmsService::testConnection($validated['test_phone']);

        if ($result['success']) {
            return redirect()->route('platform.configuration.index')
                ->with('status', "✅ {$result['message']}")
                ->with('overlay', 'sms');
        }

        return redirect()->route('platform.configuration.index')
            ->with('error', "❌ SMS Gateway failed: {$result['message']}")
            ->with('overlay', 'sms');
    }
}
