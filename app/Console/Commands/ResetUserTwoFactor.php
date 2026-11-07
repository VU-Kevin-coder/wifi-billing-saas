<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Console\Command;

/**
 * Break-glass recovery for a user who lost both their authenticator and
 * recovery codes. Deliberately CLI-only: there is no web path to reset or
 * disable two-factor authentication.
 */
class ResetUserTwoFactor extends Command
{
    protected $signature = 'users:reset-2fa {email : Email address of the user} {--force : Skip the confirmation prompt}';

    protected $description = 'Disable two-factor authentication for a user so they can enrol again (break-glass)';

    public function handle(): int
    {
        $user = User::withoutTenancy()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $this->info('Two-factor authentication is not enabled for this user. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Disable two-factor authentication for {$user->email}? Verify the person's identity first.")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        MFA::disableMethod($user, 'totp');

        AuditLog::record('mfa.reset', $user, ['via' => 'cli']);

        $this->info("Two-factor authentication disabled for {$user->email}. They must enrol again at next login.");

        return self::SUCCESS;
    }
}
