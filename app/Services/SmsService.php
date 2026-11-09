<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsService
{
    /**
     * Send an SMS message using the configured platform gateway.
     */
    public static function send(string $to, string $message): array
    {
        $provider = PlatformSetting::get('sms_provider', 'africastalking');
        $normalizedPhone = self::normalizePhoneNumber($to);

        if (! $normalizedPhone) {
            return [
                'success' => false,
                'message' => "Invalid recipient phone number format: {$to}. Please use E.164 format like +256700000000.",
            ];
        }

        try {
            if ($provider === 'twilio') {
                return self::sendViaTwilio($normalizedPhone, $message);
            }

            return self::sendViaAfricasTalking($normalizedPhone, $message);
        } catch (Throwable $e) {
            Log::error('SMS Dispatch Exception: '.$e->getMessage(), [
                'to' => $normalizedPhone,
                'provider' => $provider,
            ]);

            return [
                'success' => false,
                'message' => 'SMS Gateway Exception: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Send SMS via Africa's Talking API.
     */
    public static function sendViaAfricasTalking(string $to, string $message): array
    {
        $username = PlatformSetting::get('sms_username', 'sandbox');
        $apiKey = PlatformSetting::get('sms_api_key');
        $environment = PlatformSetting::get('sms_environment', 'sandbox');
        $senderId = PlatformSetting::get('sms_sender_id');

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => "Africa's Talking API Key is not configured. Please enter your API Key in Configuration.",
            ];
        }

        $endpoint = ($environment === 'production')
            ? 'https://api.africastalking.com/version1/messaging'
            : 'https://api.sandbox.africastalking.com/version1/messaging';

        $payload = [
            'username' => $username,
            'to' => $to,
            'message' => $message,
        ];

        // Custom sender ID / Shortcode (not supported for standard sandbox)
        if (! empty($senderId) && $environment === 'production') {
            $payload['from'] = $senderId;
        }

        $response = Http::asForm()
            ->withHeaders([
                'apiKey' => $apiKey,
                'Accept' => 'application/json',
            ])
            ->timeout(15)
            ->post($endpoint, $payload);

        $json = $response->json();

        if ($response->successful() && isset($json['SMSMessageData']['Recipients'])) {
            $recipients = $json['SMSMessageData']['Recipients'];
            $firstRecipient = $recipients[0] ?? null;

            if ($firstRecipient) {
                $status = $firstRecipient['status'] ?? 'Unknown';
                // AT returns 'Success' or 'Sent' on success
                if (in_array(strtolower($status), ['success', 'sent'])) {
                    return [
                        'success' => true,
                        'message' => "SMS dispatched successfully to {$to} (Status: {$status}, Cost: ".($firstRecipient['cost'] ?? 'N/A').')',
                        'data' => $json,
                    ];
                }

                return [
                    'success' => false,
                    'message' => "Africa's Talking error: Recipient status '{$status}'. ".($firstRecipient['message'] ?? ''),
                    'data' => $json,
                ];
            }
        }

        $errorMessage = $json['SMSMessageData']['Message']
            ?? $json['errorMessage']
            ?? $response->body()
            ?? "HTTP Error {$response->status()}";

        return [
            'success' => false,
            'message' => "Africa's Talking API failed ({$response->status()}): {$errorMessage}",
            'data' => $json,
        ];
    }

    /**
     * Send SMS via Twilio API.
     */
    public static function sendViaTwilio(string $to, string $message): array
    {
        $accountSid = PlatformSetting::get('sms_username'); // Account SID
        $authToken = PlatformSetting::get('sms_api_key');   // Auth Token
        $fromNumber = PlatformSetting::get('sms_sender_id');

        if (empty($accountSid) || empty($authToken)) {
            return [
                'success' => false,
                'message' => 'Twilio Account SID or Auth Token is missing. Please save them in Configuration.',
            ];
        }

        if (empty($fromNumber)) {
            return [
                'success' => false,
                'message' => 'Twilio requires a valid Sender Phone Number or Messaging Service SID in the Sender ID field.',
            ];
        }

        $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->timeout(15)
            ->post($endpoint, [
                'To' => $to,
                'From' => $fromNumber,
                'Body' => $message,
            ]);

        $json = $response->json();

        if ($response->successful() && isset($json['sid'])) {
            return [
                'success' => true,
                'message' => "Twilio SMS queued successfully (SID: {$json['sid']}, Status: {$json['status']})",
                'data' => $json,
            ];
        }

        $err = $json['message'] ?? $response->body();

        return [
            'success' => false,
            'message' => "Twilio API error ({$response->status()}): {$err}",
            'data' => $json,
        ];
    }

    /**
     * Send a verification/test SMS.
     */
    public static function testConnection(string $recipientPhone): array
    {
        $platformName = PlatformSetting::get('platform_name', 'Hamnet');
        $timestamp = now()->format('H:i:s d/m/Y');
        $message = "{$platformName} SMS Test: Your gateway is operational and verified to dispatch vouchers and alerts! Sent at {$timestamp}";

        $res = self::send($recipientPhone, $message);

        if ($res['success']) {
            PlatformSetting::set('sms_status', 'Connected');
        } else {
            PlatformSetting::set('sms_status', 'Failed');
        }

        return $res;
    }

    /**
     * Normalize international phone numbers, defaulting to Uganda (+256) or configured country.
     */
    public static function normalizePhoneNumber(string $phone): ?string
    {
        // Strip non-numeric except leading plus
        $clean = preg_replace('/[^\d+]/', '', trim($phone));

        if (empty($clean)) {
            return null;
        }

        // Already has international format +256...
        if (str_starts_with($clean, '+')) {
            return $clean;
        }

        // Starts with 256... (e.g. 256772123456)
        if (str_starts_with($clean, '256') && strlen($clean) >= 12) {
            return '+'.$clean;
        }

        // Starts with 254 (Kenya), 255 (Tanzania), 250 (Rwanda)
        if ((str_starts_with($clean, '254') || str_starts_with($clean, '255') || str_starts_with($clean, '250')) && strlen($clean) >= 12) {
            return '+'.$clean;
        }

        // Local format 07... (10 digits in Uganda) -> replace 0 with +256
        if (str_starts_with($clean, '0') && strlen($clean) === 10) {
            return '+256'.substr($clean, 1);
        }

        // Default: prepend +
        return '+'.$clean;
    }
}
