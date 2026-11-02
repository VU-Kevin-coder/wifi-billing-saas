<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PlatformMailService
{
    /**
     * Configure Laravel's SMTP mailer dynamically from PlatformSetting.
     */
    public static function configureMailer(): bool
    {
        $host = PlatformSetting::get('smtp_host');
        if (empty($host)) {
            return false;
        }

        $port = (int) PlatformSetting::get('smtp_port', 587);
        $encryption = PlatformSetting::get('smtp_encryption', 'tls');
        $username = PlatformSetting::get('smtp_username');
        $password = PlatformSetting::get('smtp_password');
        $fromAddress = PlatformSetting::get('mail_from_address', config('mail.from.address', 'notifications@hamnet.com'));
        $fromName = PlatformSetting::get('mail_from_name', config('mail.from.name', 'Hamnet Platform'));

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.encryption' => ($encryption === 'none' || empty($encryption)) ? null : $encryption,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        Mail::purge('smtp');

        return true;
    }

    /**
     * Send a raw or HTML email to recipient.
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array
    {
        try {
            self::configureMailer();

            Mail::html($htmlBody, function ($message) use ($to, $subject, $textBody) {
                $fromAddress = PlatformSetting::get('mail_from_address', config('mail.from.address'));
                $fromName = PlatformSetting::get('mail_from_name', config('mail.from.name', 'Hamnet Platform'));

                if ($fromAddress) {
                    $message->from($fromAddress, $fromName);
                }

                $message->to($to)->subject($subject);

                if ($textBody) {
                    $message->text($textBody);
                }
            });

            return [
                'success' => true,
                'message' => "Email sent successfully to {$to}",
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Perform a connection and test email dispatch to verify SMTP settings.
     */
    public static function testConnection(string $recipientEmail): array
    {
        $host = PlatformSetting::get('smtp_host');
        if (empty($host)) {
            return [
                'success' => false,
                'message' => 'SMTP Host is not configured yet. Please enter your SMTP details first.',
            ];
        }

        $platformName = PlatformSetting::get('platform_name', 'Hamnet');
        $fromAddress = PlatformSetting::get('mail_from_address', 'notifications@hamnet.com');
        $timestamp = now()->format('Y-m-d H:i:s T');

        $subject = "✅ {$platformName} SMTP Gateway Test";
        $htmlContent = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background-color: #ffffff;'>
            <div style='display: flex; align-items: center; margin-bottom: 20px;'>
                <h2 style='color: #2563eb; margin: 0;'>{$platformName}</h2>
            </div>
            <div style='background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 16px; margin-bottom: 20px;'>
                <h3 style='color: #065f46; margin: 0 0 8px 0;'>SMTP Gateway Connected Successfully</h3>
                <p style='color: #047857; margin: 0; font-size: 14px;'>This is a verification message confirming that your SMTP email service is correctly configured and capable of dispatching transactional emails.</p>
            </div>
            <table style='width: 100%; border-collapse: collapse; font-size: 13px; color: #374151; margin-bottom: 20px;'>
                <tr style='border-bottom: 1px solid #f3f4f6;'>
                    <td style='padding: 8px 0; font-weight: bold;'>SMTP Host:</td>
                    <td style='padding: 8px 0; font-family: monospace;'>{$host}</td>
                </tr>
                <tr style='border-bottom: 1px solid #f3f4f6;'>
                    <td style='padding: 8px 0; font-weight: bold;'>Port:</td>
                    <td style='padding: 8px 0;'>".PlatformSetting::get('smtp_port', 587)." (".PlatformSetting::get('smtp_encryption', 'tls').")</td>
                </tr>
                <tr style='border-bottom: 1px solid #f3f4f6;'>
                    <td style='padding: 8px 0; font-weight: bold;'>From:</td>
                    <td style='padding: 8px 0;'>{$fromAddress}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold;'>Dispatched At:</td>
                    <td style='padding: 8px 0;'>{$timestamp}</td>
                </tr>
            </table>
            <p style='font-size: 12px; color: #9ca3af; margin: 0;'>Sent automatically by {$platformName} WiFi Billing SaaS.</p>
        </div>";

        $res = self::send($recipientEmail, $subject, $htmlContent, "This is a test email from {$platformName} verifying SMTP configuration.");

        if ($res['success']) {
            PlatformSetting::set('email_status', 'Connected');
        } else {
            PlatformSetting::set('email_status', 'Failed');
        }

        return $res;
    }
}

