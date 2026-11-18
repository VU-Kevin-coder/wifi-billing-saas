<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Subscriber;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SubscriberController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenantId = $context->getTenantId();
        $packages = Package::query()->get()->filter(
            fn (Package $package): bool => in_array('PPPoE', (array) $package->features, true),
        )->values();
        $subscribers = Subscriber::query()
            ->with('package')
            ->latest()
            ->paginate(25);

        return view('operator.subscribers.index', compact('packages', 'subscribers', 'tenantId'));
    }

    public function store(Request $request, TenantContext $context): Response
    {
        $tenantId = $context->getTenantId();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('tenant_id', $tenantId)],
        ]);

        $package = Package::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->findOrFail($validated['package_id']);
        if (! in_array('PPPoE', (array) $package->features, true)) {
            return back()->withInput()->withErrors([
                'package_id' => 'Select a package configured for PPPoE service.',
            ]);
        }

        $username = 'ppp'.base_convert((string) $tenantId, 10, 36).Str::lower(Str::random(12));
        $password = Str::password(24, true, true, false);
        $subscriber = Subscriber::create([
            'tenant_id' => $tenantId,
            'package_id' => $package->id,
            'created_by' => $request->user()?->id,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'username' => $username,
            'password' => $password,
            'status' => 'active',
            'expires_at' => $package->expiresAt(now()),
        ]);

        AuditLog::record('subscriber.pppoe_created', $subscriber, [
            'package_id' => $package->id,
            'expires_at' => $subscriber->expires_at?->toIso8601String(),
        ]);

        return response()
            ->view('operator.subscribers.credentials', compact('subscriber', 'password'))
            ->withHeaders([
                'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }

    public function update(Request $request, Subscriber $subscriber)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $previousStatus = $subscriber->status;
        $subscriber->forceFill(['status' => $validated['status']])->saveOrFail();
        if ($previousStatus !== $subscriber->status) {
            AuditLog::record('subscriber.pppoe_status_changed', $subscriber, [
                'from' => $previousStatus,
                'to' => $subscriber->status,
            ]);
        }

        return redirect()->route('operator.subscribers.index')
            ->with('status', 'PPPoE subscriber status updated.');
    }
}
