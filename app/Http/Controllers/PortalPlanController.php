<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\RenewLink;
use App\Support\ReportingTime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "My plan": a customer checks what they bought and renews in one step.
 * No account: the link token (sent by SMS) or the voucher code they already
 * hold is the credential, and the page reveals only that one plan.
 */
class PortalPlanController extends Controller
{
    public function form(string $tenant)
    {
        return view('portal.plan-lookup', ['tenant' => $this->tenant($tenant)]);
    }

    public function lookup(Request $request, RenewLink $links, string $tenant)
    {
        $business = $this->tenant($tenant);
        $request->validate(['voucher_code' => ['required', 'string', 'max:64']]);

        $voucher = Voucher::withoutTenancy()
            ->where('tenant_id', $business->id)
            ->where('code', strtoupper(trim($request->input('voucher_code'))))
            ->first();

        $session = $voucher
            ? Session::withoutTenancy()->where('tenant_id', $business->id)->where('voucher_id', $voucher->id)->latest('id')->first()
            : null;

        // One answer for "no such code" and "code never used", so this cannot be used to probe codes.
        if (! $session) {
            return back()->withErrors(['voucher_code' => 'We could not find a plan for that code.'])->withInput();
        }

        return redirect()->route('portal.plan.show', ['tenant' => $business->slug, 'token' => $links->tokenFor($session)]);
    }

    public function show(string $tenant, string $token)
    {
        $business = $this->tenant($tenant);

        $session = Session::withoutTenancy()
            ->where('tenant_id', $business->id)
            ->where('renew_token', $token)
            ->first();
        abort_unless($session, 404);

        $package = $session->package_id
            ? Package::withoutTenancy()->where('tenant_id', $business->id)->find($session->package_id)
            : null;
        $voucher = $session->voucher_id ? Voucher::withoutTenancy()->find($session->voucher_id) : null;

        $active = $session->status === 'active' && $session->expires_at && $session->expires_at->isFuture();
        $local = fn ($moment) => $moment?->copy()->setTimezone(ReportingTime::timezone());

        return view('portal.plan', [
            'tenant' => $business,
            'package' => $package,
            'active' => $active,
            'expiresAt' => $local($session->expires_at),
            'minutesLeft' => $active ? (int) ceil(now()->diffInMinutes($session->expires_at)) : 0,
            'bytesUsed' => (int) $session->bytes_in + (int) $session->bytes_out,
            'canRenew' => $package && $package->is_published,
            'phone' => (string) ($voucher?->redeemed_by ?? ''),
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'purchaseKey' => Str::random(32),
        ]);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)->firstOrFail();
    }
}
