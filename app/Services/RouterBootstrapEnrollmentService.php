<?php

namespace App\Services;

use App\Jobs\VerifyRadiusReadinessJob;
use App\Models\Router;
use App\Models\RouterOnboarding;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RouterBootstrapEnrollmentService
{
    public function __construct(
        private RouterApiCertificateTrustService $certificateTrust,
        private RouterOnboardingService $onboarding,
    ) {}

    /**
     * @return array{router_id: int, status: string}
     */
    public function enroll(string $token, string $serial, string $model, string $certificate): array
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)
            || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $serial)
            || trim($model) === ''
            || strlen($model) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $model)) {
            throw new RuntimeException('Router enrollment identity is invalid.');
        }

        $storedCertificatePath = null;
        try {
            $result = DB::transaction(function () use (
                $token,
                $serial,
                $model,
                $certificate,
                &$storedCertificatePath,
            ): array {
                $onboarding = RouterOnboarding::query()
                    ->where('bootstrap_token_hash', hash('sha256', $token))
                    ->lockForUpdate()
                    ->first();

                if (! $onboarding
                    || $onboarding->status !== 'awaiting_bootstrap'
                    || $onboarding->expires_at->isPast()
                    || $onboarding->bootstrap_fetches < 1) {
                    throw new RuntimeException('Router enrollment token is invalid or expired.');
                }

                $router = Router::withoutTenancy()
                    ->where('tenant_id', $onboarding->tenant_id)
                    ->with('vpnPeer')
                    ->lockForUpdate()
                    ->findOrFail($onboarding->router_id);

                if (! $router->vpnPeer?->enabled || $router->enrolled) {
                    throw new RuntimeException('Router enrollment is not available for this device.');
                }

                $storedCertificatePath = $this->certificateTrust->store($router, $certificate);
                $router->forceFill([
                    'hardware_model' => trim($model),
                    'serial_number' => trim($serial),
                    'identity_verified_at' => now(),
                    'enrolled' => true,
                ])->saveOrFail();

                $this->onboarding->markConnected($onboarding);

                return [
                    'router_id' => (int) $router->id,
                    'status' => 'enrolled',
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedCertificatePath !== null && is_file($storedCertificatePath)) {
                unlink($storedCertificatePath);
            }

            throw $exception;
        }

        VerifyRadiusReadinessJob::dispatch($result['router_id'])->afterCommit();

        return $result;
    }
}
