<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function index()
    {
        // Load settings with defaults (Dynamic, no hardcoding)
        $settings = [
            // General
            'platform_name' => PlatformSetting::get('platform_name', 'Hamnet'),
            'default_country' => PlatformSetting::get('default_country', 'Uganda'),
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'timezone' => PlatformSetting::get('timezone', 'Africa/Kampala'),
            'date_format' => PlatformSetting::get('date_format', 'DD MMM YYYY'),
            'language' => PlatformSetting::get('language', 'English (Uganda)'),
            
            // Notifications & Security
            'notify_email_alerts' => PlatformSetting::get('notify_email_alerts', true),
            'notify_sms_alerts' => PlatformSetting::get('notify_sms_alerts', true),
            'enforce_2fa' => PlatformSetting::get('enforce_2fa', false),
            'session_timeout' => PlatformSetting::get('session_timeout', 30),

            // Commission
            'default_commission_rate' => PlatformSetting::get('default_commission_rate', 5.00),
        ];

        // Read-only statuses for integrations (can be updated in Batch 2)
        $integrations = [
            'mtn' => ['status' => PlatformSetting::get('mtn_status', 'Not Configured'), 'color' => 'gray'],
            'airtel' => ['status' => PlatformSetting::get('airtel_status', 'Not Configured'), 'color' => 'gray'],
            'email' => ['status' => PlatformSetting::get('email_status', 'Not Configured'), 'color' => 'gray'],
            'sms' => ['status' => PlatformSetting::get('sms_status', 'Not Configured'), 'color' => 'gray'],
        ];

        return view('platform.configuration.index', compact('settings', 'integrations'));
    }

    public function updateGeneral(Request $request)
    {
        $validated = $request->validate([
            'platform_name' => 'required|string|max:255',
            'default_country' => 'required|string|max:255',
            'currency' => 'required|string|max:10',
            'timezone' => 'required|string|max:100',
            'date_format' => 'required|string|max:50',
            'language' => 'required|string|max:100',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        foreach ($validated as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        return back()->with('success', 'General settings updated successfully.');
    }

    public function updateSecurity(Request $request)
    {
        $validated = $request->validate([
            'session_timeout' => 'required|integer|min:5|max:1440',
        ]);

        PlatformSetting::set('session_timeout', $validated['session_timeout']);
        PlatformSetting::set('enforce_2fa', $request->has('enforce_2fa'));
        PlatformSetting::set('notify_email_alerts', $request->has('notify_email_alerts'));
        PlatformSetting::set('notify_sms_alerts', $request->has('notify_sms_alerts'));

        return back()->with('success', 'Security & Notification settings updated successfully.');
    }
}

