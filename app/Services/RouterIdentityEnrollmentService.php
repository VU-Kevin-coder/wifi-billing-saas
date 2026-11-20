<?php

namespace App\Services;

use App\Models\Router;
use RuntimeException;

class RouterIdentityEnrollmentService
{
    public function __construct(private RouterIdentityService $identity) {}

    public function discover(Router $router): array
    {
        return $this->identity->discover($router);
    }

    public function enroll(Router $router): array
    {
        $detected = $this->identity->discover($router);

        if ($router->identity_verified_at) {
            if (! $this->identity->matches($router, $detected)) {
                throw new RuntimeException('Router identity changed. Replacement enrollment is not allowed.');
            }

            return [
                ...$detected,
                'identity_state' => 'verified',
            ];
        }

        $router->forceFill([
            'hardware_model' => $detected['hardware_model'],
            'serial_number' => $detected['serial_number'],
            'identity_verified_at' => now(),
        ])->saveOrFail();

        return [
            ...$detected,
            'identity_state' => 'verified',
        ];
    }
}
