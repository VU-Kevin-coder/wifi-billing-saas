<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\PlatformMailService;
use App\Services\SmsConfiguration;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConfigurationController extends Controller
{
    public function index()
    {
        return redirect()->route('platform.configuration.control');
    }

    public function updateGeneral(Request $request)
    {
        $validated = $request->validate([
            'platform_name' => 'required|string',
            'default_country' => 'required|string',
            'currency' => ['required', Rule::in(['UGX', 'KES', 'USD'])],
            'timezone' => 'required|string',
            'date_format' => 'required|string',
            'language' => 'required|string',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        AuditLog::record('platform.config.updated', null, ['section' => 'general']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'General settings updated successfully.');
    }

    public function updateSecurity(Request $request)
    {
        PlatformSetting::set('notify_email_alerts', $request->boolean('notify_email_alerts'));
        PlatformSetting::set('notify_sms_alerts', $request->boolean('notify_sms_alerts'));
        PlatformSetting::set('enforce_2fa', $request->boolean('enforce_2fa'));
        PlatformSetting::set('session_timeout', $request->input('session_timeout', 30));

        AuditLog::record('platform.config.updated', null, ['section' => 'security']);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Security settings updated successfully.');
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

        DB::transaction(function () use ($validated): void {
            $changed = false;
            foreach ($validated as $key => $value) {
                if ($key === 'smtp_password') {
                    if (filled($value)) {
                        $changed = true;
                        PlatformSetting::setSecret($key, $value);
                    }

                    continue;
                }
                $changed = $changed || PlatformSetting::get($key) !== $value;
                PlatformSetting::set($key, $value);
            }

            if ($changed) {
                PlatformSetting::set('email_status', 'Configured (Untested)');
            }

            AuditLog::record('platform.config.updated', null, ['section' => 'email']);
        });

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Email gateway configuration saved.');
    }

    public function testEmail(Request $request)
    {
        // Tests use saved settings only; callers must save changes explicitly first.
        $validated = $request->validate([
            'test_email' => 'required|email',
            'smtp_host' => 'prohibited',
            'smtp_port' => 'prohibited',
            'smtp_encryption' => 'prohibited',
            'smtp_username' => 'prohibited',
            'smtp_password' => 'prohibited',
            'mail_from_address' => 'prohibited',
            'mail_from_name' => 'prohibited',
        ]);

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
            'sms_provider' => 'required|in:africastalking,twilio,disabled',
            'sms_username' => 'nullable|string',
            'sms_api_key' => 'nullable|string',
            'sms_sender_id' => 'nullable|string',
            'sms_environment' => 'required|in:sandbox,production',
        ]);

        DB::transaction(function () use ($validated): void {
            SmsConfiguration::lock();
            SmsConfiguration::validateProviderChange($validated['sms_provider'], $validated['sms_api_key'] ?? null, 'sms_api_key');
            if (filled($validated['sms_api_key'] ?? null)) {
                PlatformSetting::set('sms_credential_provider', $validated['sms_provider']);
            }
            $changed = false;
            foreach ($validated as $key => $value) {
                if ($key === 'sms_api_key') {
                    if (filled($value)) {
                        $changed = true;
                        PlatformSetting::setSecret($key, $value);
                    }

                    continue;
                }
                $changed = $changed || PlatformSetting::get($key, match ($key) {
                    'sms_provider' => 'africastalking',
                    'sms_environment' => 'sandbox',
                    default => null,
                }) !== $value;
                PlatformSetting::set($key, $value);
            }

            if ($changed) {
                PlatformSetting::set('sms_status', 'Configured (Untested)');
            }

            AuditLog::record('platform.config.updated', null, ['section' => 'sms']);
        });

        return redirect()->route('platform.configuration.index')
            ->with('status', 'SMS gateway configuration saved.');
    }

    public function testSms(Request $request)
    {
        // Tests use saved settings only; callers must save changes explicitly first.
        $validated = $request->validate([
            'test_phone' => 'required|string|min:9',
            'sms_provider' => 'prohibited',
            'sms_username' => 'prohibited',
            'sms_api_key' => 'prohibited',
            'sms_sender_id' => 'prohibited',
            'sms_environment' => 'prohibited',
        ]);

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
