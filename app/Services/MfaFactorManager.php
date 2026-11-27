<?php

namespace App\Services;

use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use CodingLibs\MFA\Models\MfaChallenge;
use CodingLibs\MFA\Models\MfaRememberedDevice;
use Illuminate\Support\Facades\DB;

class MfaFactorManager
{
    private const METHODS = ['totp', 'email', 'sms'];

    public function enabledMethods(User $user): array
    {
        return array_values(array_filter(
            self::METHODS,
            fn (string $method): bool => MFA::isEnabled($user, $method)
        ));
    }

    public function removeMethod(User $user, string $method): void
    {
        MFA::disableMethod($user, $method);
        $this->deleteChallenges($user, $method);

        if ($method === 'totp') {
            $totp = MFA::getMethod($user, 'totp');
            if ($totp) {
                $totp->secret = null;
                $totp->save();
            }
        }

        if ($this->enabledMethods($user) === []) {
            MFA::clearRecoveryCodes($user);
        }
    }

    public function reset(User $user, bool $requireEnrollment): void
    {
        DB::transaction(function () use ($user, $requireEnrollment): void {
            foreach (self::METHODS as $method) {
                MFA::disableMethod($user, $method);
            }

            $totp = MFA::getMethod($user, 'totp');
            if ($totp) {
                $totp->secret = null;
                $totp->save();
            }

            MFA::clearRecoveryCodes($user);
            $this->deleteChallenges($user);
            $this->userOwnedRecords(MfaRememberedDevice::query(), $user)->delete();
            $user->forceFill([
                'mfa_enrollment_required_at' => $requireEnrollment ? now() : null,
                'phone_verified_at' => null,
            ])->save();
        });
    }

    public function deleteChallenges(User $user, ?string $method = null): void
    {
        $query = $this->userOwnedRecords(MfaChallenge::query(), $user);

        if ($method !== null) {
            $query->where('method', $method);
        }

        $query->delete();
    }

    private function userOwnedRecords($query, User $user)
    {
        $morphName = config('mfa.morph.name', 'model');

        return $query
            ->where($morphName.'_type', $user::class)
            ->where($morphName.'_id', $user->getKey());
    }
}
