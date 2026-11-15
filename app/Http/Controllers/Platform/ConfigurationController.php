<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Payments\ObPayGateway;
use App\Services\Payments\PawaPayGateway;
use App\Services\Payments\PaymentGatewaySettings;
use App\Services\Payments\XenteGateway;
use App\Services\PlatformMailService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

            // Payment gateways
            'payment_active_gateway' => PaymentGatewaySettings::active(),
            'obpay_configured' => PaymentGatewaySettings::configured('obpay'),
            'obpay_secret_configured' => (bool) PlatformSetting::getSecret('payment.obpay.secret_key'),
            'obpay_webhook_secret_configured' => (bool) PlatformSetting::getSecret('payment.obpay.webhook_secret'),
            'pawapay_configured' => PaymentGatewaySettings::configured('pawapay'),
            'pawapay_token_configured' => (bool) PlatformSetting::getSecret('payment.pawapay.api_token'),
            'pawapay_environment' => PlatformSetting::get('payment.pawapay.environment', 'sandbox'),
            'xente_configured' => PaymentGatewaySettings::configured('xente'),
            'xente_app_key_configured' => (bool) PlatformSetting::getSecret('payment.xente.app_key'),
            'xente_app_password_configured' => (bool) PlatformSetting::getSecret('payment.xente.app_password'),
            'xente_user_id_configured' => (bool) PlatformSetting::getSecret('payment.xente.user_id'),
            'xente_products' => [
                'collection_mtn' => PlatformSetting::get('payment.xente.collection_mtn'),
                'collection_airtel' => PlatformSetting::get('payment.xente.collection_airtel'),
                'payout_mtn' => PlatformSetting::get('payment.xente.payout_mtn'),
                'payout_airtel' => PlatformSetting::get('payment.xente.payout_airtel'),
            ],

            // Email (SMTP)
            'smtp_host' => PlatformSetting::get('smtp_host'),
            'smtp_port' => PlatformSetting::get('smtp_port', 587),
            'smtp_encryption' => PlatformSetting::get('smtp_encryption', 'tls'),
            'smtp_username' => PlatformSetting::get('smtp_username'),
            'smtp_password' => PlatformSetting::get('smtp_password'),
            'mail_from_address' => PlatformSetting::get('mail_from_address', config('mail.from.address')),
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

    public function updatePayments(Request $request, XenteGateway $xente)
    {
        $validated = $request->validate([
            'active_gateway' => ['nullable', Rule::in(PaymentGatewaySettings::PROVIDERS)],
            'obpay_secret_key' => 'nullable|string|max:4096',
            'obpay_webhook_secret' => 'nullable|string|max:4096',
            'pawapay_api_token' => 'nullable|string|max:4096',
            'pawapay_environment' => 'required|in:sandbox,production',
            'xente_app_key' => 'nullable|string|max:4096',
            'xente_app_password' => 'nullable|string|max:4096',
            'xente_user_id' => 'nullable|string|max:4096',
            'xente_collection_mtn' => 'nullable|string|max:255',
            'xente_collection_airtel' => 'nullable|string|max:255',
            'xente_payout_mtn' => 'nullable|string|max:255',
            'xente_payout_airtel' => 'nullable|string|max:255',
        ]);

        foreach ([
            'obpay_secret_key' => 'payment.obpay.secret_key',
            'obpay_webhook_secret' => 'payment.obpay.webhook_secret',
            'pawapay_api_token' => 'payment.pawapay.api_token',
            'xente_app_key' => 'payment.xente.app_key',
            'xente_app_password' => 'payment.xente.app_password',
            'xente_user_id' => 'payment.xente.user_id',
        ] as $input => $setting) {
            if ($request->filled($input)) {
                PlatformSetting::setSecret($setting, $validated[$input]);
            }
        }

        $activeGateway = $validated['active_gateway'] ?? '';
        $productSettings = [
            'xente_collection_mtn' => ['setting' => 'payment.xente.collection_mtn', 'operation' => 'collection', 'network' => 'mtn'],
            'xente_collection_airtel' => ['setting' => 'payment.xente.collection_airtel', 'operation' => 'collection', 'network' => 'airtel'],
            'xente_payout_mtn' => ['setting' => 'payment.xente.payout_mtn', 'operation' => 'payout', 'network' => 'mtn'],
            'xente_payout_airtel' => ['setting' => 'payment.xente.payout_airtel', 'operation' => 'payout', 'network' => 'airtel'],
        ];
        $requestedProducts = [];
        $currentProducts = [];
        foreach ($productSettings as $input => $product) {
            $currentProducts[$input] = (string) PlatformSetting::get($product['setting'], '');
            $requestedProducts[$input] = array_key_exists($input, $validated)
                ? ($validated[$input] ?? '')
                : $currentProducts[$input];
        }

        if ($activeGateway === 'xente' && in_array('', $requestedProducts, true)) {
            return back()->withErrors([
                'active_gateway' => 'Load and select all four Xente mobile-money products before enabling Xente.',
            ])->withInput();
        }

        $verifyProducts = $activeGateway === 'xente'
            || ($requestedProducts !== $currentProducts && count(array_filter($requestedProducts)) > 0);
        if ($verifyProducts) {
            try {
                $availableItems = $xente->availableProductItems();
            } catch (\Throwable $exception) {
                report($exception);

                return back()->withErrors([
                    'active_gateway' => 'Unable to verify Xente product selections. Check saved Xente credentials and load the product catalog again.',
                ])->withInput();
            }

            foreach ($productSettings as $input => $product) {
                $selectedId = $requestedProducts[$input];
                if ($selectedId === '') {
                    continue;
                }
                $matches = collect($availableItems)->contains(
                    fn (array $item): bool => $item['id'] === $selectedId
                        && $item['operation'] === $product['operation']
                        && $item['network'] === $product['network']
                );
                if (! $matches) {
                    return back()->withErrors([
                        $input => 'The selected Xente product does not match the requested network and transaction type. Reload the product list.',
                    ])->withInput();
                }
            }
        }

        foreach ([
            'xente_collection_mtn',
            'xente_collection_airtel',
            'xente_payout_mtn',
            'xente_payout_airtel',
        ] as $input) {
            if (array_key_exists($input, $validated)) {
                PlatformSetting::set($productSettings[$input]['setting'], $validated[$input] ?? '');
            }
        }

        PlatformSetting::set('payment.pawapay.environment', $validated['pawapay_environment']);
        if ($activeGateway !== '' && ! PaymentGatewaySettings::configured($activeGateway)) {
            return back()->withErrors([
                'active_gateway' => 'Configure valid credentials and all required provider product selections before enabling this gateway.',
            ]);
        }

        PlatformSetting::set('payment.active_gateway', $activeGateway);
        AuditLog::record('platform.config.updated', null, [
            'section' => 'payments',
            'active_gateway' => $activeGateway ?: null,
            'credentials_changed' => array_values(array_filter(
                array_keys($validated),
                fn (string $key): bool => str_ends_with($key, '_key')
                    || str_ends_with($key, '_password')
                    || str_ends_with($key, '_token')
                    || str_ends_with($key, '_secret')
            )),
        ]);

        return redirect()->route('platform.configuration.index')
            ->with('status', 'Payment gateway configuration saved.');
    }

    public function testPaymentGateway(string $provider, ObPayGateway $obpay, PawaPayGateway $pawapay, XenteGateway $xente): JsonResponse
    {
        abort_unless(in_array($provider, PaymentGatewaySettings::PROVIDERS, true), 404);

        try {
            $connected = match ($provider) {
                'obpay' => $obpay->ping(),
                'pawapay' => $pawapay->activeConfiguration('DEPOSIT') !== [],
                'xente' => $xente->products() !== [],
            };
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'message' => 'Connection check failed. Verify the saved credentials and provider account.'], 422);
        }

        return response()->json([
            'ok' => $connected,
            'message' => $connected ? 'Provider connection succeeded.' : 'Provider did not confirm the connection.',
        ], $connected ? 200 : 422);
    }

    public function xenteProducts(XenteGateway $xente): JsonResponse
    {
        try {
            return response()->json(['products' => $xente->availableProductItems()]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to load Uganda mobile-money products. Verify Xente credentials and account permissions.',
            ], 422);
        }
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

        AuditLog::record('platform.config.updated', null, ['section' => 'email']);

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

        AuditLog::record('platform.config.updated', null, ['section' => 'sms']);

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
