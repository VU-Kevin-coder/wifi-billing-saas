<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RouterCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RouterCommandController extends Controller
{
    public function poll(Request $request, RouterCommandService $commands): Response
    {
        $router = $commands->authenticatePollingToken($request->bearerToken());
        if (! $router) {
            return response('unauthorized', 401, $this->noStoreHeaders());
        }

        $telemetry = $request->validate([
            'cpu_usage' => ['required', 'integer', 'min:0', 'max:100'],
            'memory_usage' => ['required', 'integer', 'min:0', 'max:100'],
            'active_users' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $command = $commands->poll($router, $telemetry);
        $body = $command
            ? implode('|', [
                $command['command_id'],
                $command['lease_token'],
                $command['operation'],
                $command['target_service'],
                $command['username'],
            ])
            : 'none';

        return response($body, 200, [
            ...$this->noStoreHeaders(),
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function acknowledge(Request $request, RouterCommandService $commands): JsonResponse
    {
        $router = $commands->authenticatePollingToken($request->bearerToken());
        if (! $router) {
            return response()->json(['message' => 'Unauthorized.'], 401, $this->noStoreHeaders());
        }

        $validated = $request->validate([
            'command_id' => ['required', 'uuid'],
            'lease_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'result_code' => ['required', 'string', 'in:kicked,already_absent'],
        ]);

        if (! $commands->acknowledgePoll(
            $router,
            $validated['command_id'],
            $validated['lease_token'],
            $validated['result_code'],
        )) {
            return response()->json([
                'message' => 'The command lease is invalid or has expired.',
            ], 409, $this->noStoreHeaders());
        }

        return response()->json(['accepted' => true], 200, $this->noStoreHeaders());
    }

    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
