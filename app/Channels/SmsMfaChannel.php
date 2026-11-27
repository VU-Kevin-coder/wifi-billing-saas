<?php

namespace App\Channels;

use App\Models\User;
use App\Services\SmsService;
use CodingLibs\MFA\Contracts\MfaChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class SmsMfaChannel implements MfaChannel
{
    public function getName(): string
    {
        return 'sms';
    }

    public function send(Authenticatable $user, string $code, array $options = []): void
    {
        if (! $user instanceof User || blank($user->phone)) {
            throw new RuntimeException('The user does not have a phone number for MFA delivery.');
        }

        $result = SmsService::send(
            $user->phone,
            'Hamnet verification code: '.$code.'. It expires in 5 minutes. Do not share this code.'
        );

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('The configured SMS gateway did not accept the MFA message.');
        }
    }
}
