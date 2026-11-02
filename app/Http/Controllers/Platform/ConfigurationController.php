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

            // MTN
            'mtn_api_user' => PlatformSetting::get('mtn_api_user'),
            'mtn_api_key' => PlatformSetting::get('mtn_api_key'),
            'mtn_subscription_key' => PlatformSetting::get('mtn_subscription_key'),
            'mtn_environment' => PlatformSetting::get('mtn_environment', 'sandbox'),
            'mtn_status' => PlatformSetting::get('mtn_status', 'Not Connected'),
            'mtn_callback_url' => PlatformSetting::get('mtn_callback_url', url('/api/webhooks/mtn')),

            // Airtel
            'airtel_client_id' => PlatformSetting::get('airtel_client_id'),
            'airtel_client_secret' => PlatformSetting::get('airtel_client_secret'),
            'airtel_environment' => PlatformSetting::get('airtel_environment', 'sandbox'),
            'airtel_status' => PlatformSetting::get('airtel_status', 'Not Connected'),
            'airtel_callback_url' => PlatformSetting::get('airtel_callback_url', url('/api/webhooks/airtel')),

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

        return redirect()->route('platform.configuration.index')
            ->with('status', 'General settings updated successfully.');
    }

    public function updateSecurity(Request $request)
    {
        PlatformSetting::set('notify_email_alerts', $request->boolean('notify_email_alerts'));
        PlatformSetting::set('notify_sms_alerts', $request->boolean('notify_sms_alerts'));
        PlatformSetting::set('enforce_2fa', $request->boolean('enforce_2fa'));
        PlatformSetting::set('session_timeout', $request->input('session_timeout', 30));

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Security settings updated successfully.');
    }

    public function updateMtn(Request $request)
    {
        $validated = $request->validate([
            'mtn_api_user' => 'nullable|string',
            'mtn_api_key' => 'nullable|string',
            'mtn_subscription_key' => 'nullable|string',
            'mtn_environment' => 'required|in:sandbox,production',
            'mtn_callback_url' => 'required|url',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }
        PlatformSetting::set('mtn_status', 'Connected');

        return redirect()->route('platform.configuration.index')
            ->with('status', 'MTN MoMo configuration saved.');
    }

    public function updateAirtel(Request $request)
    {
        $validated = $request->validate([
            'airtel_client_id' => 'nullable|string',
            'airtel_client_secret' => 'nullable|string',
            'airtel_environment' => 'required|in:sandbox,production',
            'airtel_callback_url' => 'required|url',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }
        PlatformSetting::set('airtel_status', 'Connected');

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

        if (!empty($validated['smtp_host'])) {
            PlatformSetting::set('email_status', 'Configured (Untested)');
        }

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

        if (!empty($validated['sms_api_key'])) {
            PlatformSetting::set('sms_status', 'Configured (Untested)');
        }

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
