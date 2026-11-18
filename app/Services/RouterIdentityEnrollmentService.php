<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RouterIdentityEnrollmentService
{
    public function enroll(Router $router, array $identity): Router
    {
        $hardwareModel = trim((string) ($identity['hardware_model'] ?? ''));
        $serialNumber = trim((string) ($identity['serial_number'] ?? ''));
        if ($hardwareModel === '' || $serialNumber === '') {
            throw new RuntimeException('Router did not report both a board name and a serial number.');
        }

        return DB::transaction(function () use ($router, $hardwareModel, $serialNumber): Router {
            $lockedRouter = Router::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->lockForUpdate()
                ->findOrFail($router->id);

            if ($lockedRouter->identity_verified_at) {
                if (! app(RouterIdentityService::class)->matches([
                    'hardware_model' => $hardwareModel,
                    'serial_number' => $serialNumber,
                ], [
                    'hardware_model' => $lockedRouter->hardware_model,
                    'serial_number' => $lockedRouter->serial_number,
                ])) {
                    throw new RuntimeException('Enrolled router identity cannot be replaced through enrollment.');
                }

                return $lockedRouter;
            }

            $lockedRouter->forceFill([
                'hardware_model' => $hardwareModel,
                'serial_number' => $serialNumber,
                'identity_verified_at' => now(),
            ])->save();

            return $lockedRouter;
        });
    }
}
