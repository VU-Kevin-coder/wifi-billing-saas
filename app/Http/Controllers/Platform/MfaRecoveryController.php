<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaFactorManager;
use App\Services\Policy\Policy;
use App\Support\MfaSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MfaRecoveryController extends Controller
{
    public function index(): View
    {
        $users = User::withoutTenancy()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'platform_admin'))
            ->with('tenant:id,name')
            ->orderBy('name')
            ->paginate(20);

        return view('platform.mfa-recovery.index', compact('users'));
    }

    public function reset(Request $request, int $target, MfaFactorManager $mfaFactors): RedirectResponse
    {
        $target = User::withoutTenancy()->findOrFail($target);
        abort_if($target->isPlatformAdmin(), 403, 'Platform administrator MFA resets are CLI-only.');

        $actor = $request->user();
        if (! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security.index')
                ->with('error', "Enable two-factor authentication before resetting another user's methods.");
        }

        $window = (int) Policy::get(
            'security.step_up_minutes',
            config('security.step_up_minutes', 10)
        );
        if (! MfaSession::isRecent($request, $window)) {
            $request->session()->put('mfa.step_up_return', route('platform.mfa-recovery.index'));

            return redirect()->route('mfa.step-up')
                ->with('error', "Confirm your identity before resetting another user's methods.");
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $methods = $mfaFactors->enabledMethods($target);

        DB::transaction(function () use ($mfaFactors, $target, $methods, $validated, $actor): void {
            $mfaFactors->reset($target, requireEnrollment: true);

            AuditLog::record('mfa.admin_reset', $target, [
                'methods' => $methods,
                'reason' => $validated['reason'],
                'via' => 'platform_admin',
            ], $actor);
        });

        return back()->with('status', "MFA was reset for {$target->email}. They must enroll again at their next sign-in.");
    }
}
