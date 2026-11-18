<?php

namespace App\Services;

use App\Models\Router;
use InvalidArgumentException;

class RouterAccessStrategyResolver
{
    public function resolve(Router $router): RouterAccessStrategy
    {
        return match ($router->access_backend) {
            'site_agent' => app(SiteAgentAccessStrategy::class),
            'radius' => app(RadiusAccessStrategy::class),
            default => throw new InvalidArgumentException('Unsupported router access backend.'),
        };
    }
}
