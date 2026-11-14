<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RadiusAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RadiusPolicyController extends Controller
{
    public function authorize(Request $request, RadiusAccountingService $accounting): JsonResponse
    {
        $validated = $request->validate([
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'nas_session_id' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'voucher_code' => ['required', 'string', 'max:128'],
        ]);

        return response()->json($accounting->authorize(
            $validated['nas_identifier'],
            $validated['nas_session_id'],
            $validated['voucher_code'],
        ));
    }

    public function accountingEvent(Request $request, RadiusAccountingService $accounting): JsonResponse
    {
        $validated = $request->validate([
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'nas_session_id' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'status_type' => ['required', 'string', Rule::in(['Start', 'Interim-Update', 'Stop'])],
            'event_time' => ['nullable', 'date'],
            'session_time' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'input_octets' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'input_gigawords' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'output_octets' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'output_gigawords' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'termination_cause' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._ -]*$/D'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'string', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D'],
            'voucher_code' => ['nullable', 'string', 'max:128'],
        ]);

        return response()->json($accounting->ingestAccountingEvent($validated));
    }

    public function disconnectCommands(RadiusAccountingService $accounting): JsonResponse
    {
        return response()->json(['commands' => $accounting->claimDisconnectCommands()]);
    }

    public function acknowledgeDisconnect(
        Request $request,
        string $command_id,
        RadiusAccountingService $accounting,
    ): JsonResponse {
        $validated = $request->validate([
            'result' => ['required', 'string', Rule::in(['delivered', 'retryable_failure'])],
            'result_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]*$/D'],
        ]);

        $result = $accounting->acknowledgeDisconnectCommand(
            $command_id,
            $validated['result'],
            $validated['result_code'] ?? null,
        );

        if (! $result['found']) {
            return response()->json(['message' => 'Command not found.'], 404);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'delivery_state' => $result['state'],
        ]);
    }
}
