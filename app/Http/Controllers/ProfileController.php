<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\MfaFactorManager;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, MfaFactorManager $mfaFactors): RedirectResponse
    {
        $user = $request->user();
        $hadMfaEnabled = $user->hasEnabledTwoFactorAuthentication();
        $user->fill($request->validated());
        $emailChanged = $user->isDirty('email');
        $phoneChanged = $user->isDirty('phone');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($phoneChanged) {
            $user->phone_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $mfaFactors->deleteChallenges($user, 'email');
            if (MFA::isEnabled($user, 'email')) {
                $mfaFactors->removeMethod($user, 'email');
            }
        }

        if ($phoneChanged) {
            $mfaFactors->deleteChallenges($user, 'sms');
            if (MFA::isEnabled($user, 'sms')) {
                $mfaFactors->removeMethod($user, 'sms');
            }
        }

        if ($emailChanged || $phoneChanged) {
            $request->session()->forget('mfa.enrollment.method');
        }

        if ($hadMfaEnabled && ! $user->hasEnabledTwoFactorAuthentication()) {
            $user->forceFill(['mfa_enrollment_required_at' => now()])->save();
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
