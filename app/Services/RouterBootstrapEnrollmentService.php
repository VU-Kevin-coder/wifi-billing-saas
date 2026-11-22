<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterOnboarding;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RouterBootstrapEnrollmentService
{
    public function __construct(
        private RouterOnboardingService $onboarding,
    ) {}

    /**
     * @return array{router_id: int, status: string}
     */
    public function enroll(string $token, string $serial, string $model, string $certificate): void
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)
            || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $serial)
            || trim($model) === ''
            || strlen($model) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $model)) {
            throw new RuntimeException('Router enrollment identity is invalid.');
        }

        DB::transaction(function () use ($token, $serial, $model, $certificate): void {
            $onboarding = RouterOnboarding::query()
                ->where('bootstrap_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $onboarding) {
                throw new RuntimeException('Router enrollment token is invalid or expired. (token not found: already used, or the onboarding was regenerated)');
            }
            if ($onboarding->status !== 'awaiting_bootstrap') {
                throw new RuntimeException('Router enrollment token is invalid or expired. (onboarding status is '.$onboarding->status.')');
            }
            if ($onboarding->expires_at->isPast()) {
                throw new RuntimeException('Router enrollment token is invalid or expired. (token expired)');
            }
            if ($onboarding->bootstrap_fetches < 1) {
                throw new RuntimeException('Router enrollment token is invalid or expired. (bootstrap script was never fetched from /api/provision/bootstrap; do not paste the .rsc contents directly)');
            }

            $router = Router::withoutTenancy()
                ->where('tenant_id', $onboarding->tenant_id)
                ->with('vpnPeer')
                ->lockForUpdate()
                ->findOrFail($onboarding->router_id);

            if (! $router->vpnPeer?->enabled) {
                throw new RuntimeException('Router enrollment is not available for this device. (WireGuard peer missing or disabled)');
            }
            if ($router->enrolled) {
                throw new RuntimeException('Router enrollment is not available for this device. (router is already enrolled)');
            }

            $router->forceFill([
                'api_ca_certificate' => $certificate,
                'hardware_model' => trim($model),
                'serial_number' => trim($serial),
                'identity_verified_at' => now(),
                'enrolled' => true,
            ])->saveOrFail();

            $this->onboarding->markEnrolled($onboarding);
        });
    }
}
