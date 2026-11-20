<?php

namespace App\Services;

use App\Models\Router;
use InvalidArgumentException;

class RouterAccessStrategyResolver
{
    public function resolve(Router $router): RouterAccessStrategy
    {
        if ($router->access_backend !== 'radius') {
            throw new InvalidArgumentException('Router must be configured for RADIUS before access can be activated.');
        }

        return app(RadiusAccessStrategy::class);
    }
}
