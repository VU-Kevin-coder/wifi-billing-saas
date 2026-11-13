<?php

namespace App\Services;

use App\Models\Router;
use InvalidArgumentException;
use LogicException;

class RadiusRouterCredentialService
{
    /**
     * Opt one persisted router into RADIUS and return its generated secret once.
     * The model's encrypted cast protects storage and its hidden field prevents
     * ordinary model serialization from returning this credential.
     */
    public function optIn(Router $router, string $nasIdentifier): string
    {
        $nasIdentifier = trim($nasIdentifier);

        if (! $router->exists || ! $router->getKey()) {
            throw new LogicException('A persisted router is required before RADIUS can be configured.');
        }

        if ($nasIdentifier === '' || strlen($nasIdentifier) > 255 || preg_match('/[\x00-\x20\x7f]/', $nasIdentifier)) {
            throw new InvalidArgumentException('NAS identity must be a non-empty, bounded identifier without control characters.');
        }

        $secret = bin2hex(random_bytes(32));

        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => $nasIdentifier,
            'radius_shared_secret' => $secret,
        ])->saveOrFail();

        return $secret;
    }
}
