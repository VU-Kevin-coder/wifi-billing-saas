<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function ready(SystemHealthService $health): JsonResponse
    {
        $snapshot = $health->checkAndNotify();
        $checks = collect($snapshot['checks'])
            ->map(fn (array $check): string => $check['status']);

        return response()->json([
            'status' => $snapshot['overall'],
            'checked_at' => $snapshot['checked_at'],
            'checks' => $checks,
        ], $snapshot['overall'] === 'healthy' ? 200 : 503);
    }
}
