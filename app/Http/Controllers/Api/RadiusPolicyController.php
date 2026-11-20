<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\RadiusAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RadiusPolicyController extends Controller
{
    public function clientLookup(Request $request): JsonResponse
    {
        $router = $request->attributes->get('authenticated_router');
        $peerIp = $request->attributes->get('authenticated_peer_ip');
        if (! $router instanceof Router || ! is_string($peerIp)) {
            return response()->json(['message' => 'RADIUS client not found.'], 404, [
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $sharedSecret = $router->radius_shared_secret;
        if (! is_string($sharedSecret) || $sharedSecret === '') {
            return response()->json(['message' => 'RADIUS client not found.'], 404, [
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $attribute = static fn (string $value): array => [
            'value' => [$value],
            'do_xlat' => false,
        ];

        return response()->json([
            'control:FreeRADIUS-Client-IP-Address' => $attribute($peerIp),
            'control:FreeRADIUS-Client-Secret' => $attribute($sharedSecret),
            'control:FreeRADIUS-Client-Shortname' => $attribute('router-'.$router->id),
            'control:FreeRADIUS-Client-NAS-Type' => $attribute('other'),
            'control:FreeRADIUS-Client-Require-MA' => $attribute('yes'),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function authorize(Request $request, RadiusAccountingService $accounting): JsonResponse
    {
        $validated = $request->validate([
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'nas_session_id' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'voucher_code' => ['required', 'string', 'max:128'],
            'service_type' => ['nullable', 'string', 'max:32'],
        ]);

        $routerId = (int) $request->attributes->get('authenticated_router_id');
        $peerIp = (string) $request->attributes->get('authenticated_peer_ip');
        $decision = ($validated['service_type'] ?? '') === 'Framed-User'
            ? $accounting->authorizePppoeForPeer(
                $routerId,
                $peerIp,
                $validated['nas_identifier'],
                $validated['nas_session_id'] ?? '',
                $validated['voucher_code'],
            )
            : $accounting->authorizeForPeer(
                $routerId,
                $peerIp,
                $validated['nas_identifier'],
                $validated['nas_session_id'] ?? '',
                $validated['voucher_code'],
            );

        if ($decision['decision'] !== 'accept') {
            return response()->json(['message' => 'Access rejected.'], 401, [
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $policy = $decision['policy'];
        $response = [
            'control:Cleartext-Password' => [
                'value' => [$decision['cleartext_password'] ?? $validated['voucher_code']],
                'do_xlat' => false,
            ],
            'reply:Session-Timeout' => $policy['session_timeout_seconds'],
            'reply:Port-Limit' => $policy['device_limit'],
            'reply:Mikrotik-Rate-Limit' => $policy['rate_limit_mbps'].'M/'.$policy['rate_limit_mbps'].'M',
        ];
        if ($policy['data_limit_bytes'] !== null) {
            $response['reply:Mikrotik-Total-Limit'] = $policy['data_limit_bytes'] % 4294967296;
            $gigawords = intdiv($policy['data_limit_bytes'], 4294967296);
            if ($gigawords > 0) {
                $response['reply:Mikrotik-Total-Limit-Gigawords'] = $gigawords;
            }
        }

        return response()->json($response, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function accountingEvent(Request $request, RadiusAccountingService $accounting): Response
    {
        $validated = $request->validate([
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'nas_session_id' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'status_type' => ['required', 'string', Rule::in(['Start', 'Interim-Update', 'Stop'])],
            'event_time' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9 :+.-]*$/D'],
            'session_time' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'input_octets' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'input_gigawords' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'output_octets' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'output_gigawords' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'termination_cause' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._ -]*$/D'],
            'username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'service_type' => ['nullable', 'string', 'max:32'],
        ]);

        $authenticatedIdentity = [
            'authenticated_router_id' => (int) $request->attributes->get('authenticated_router_id'),
            'authenticated_peer_ip' => (string) $request->attributes->get('authenticated_peer_ip'),
        ];
        if (($validated['service_type'] ?? '') === 'Framed-User') {
            $accounting->recordPppoeAccountingEvent($validated + $authenticatedIdentity);
        } else {
            $accounting->recordLabAccountingObservation($validated + $authenticatedIdentity);
        }

        return response()->noContent();
    }

    public function postAuthEvent(Request $request, RadiusAccountingService $accounting): Response
    {
        $validated = $request->validate([
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'outcome' => ['required', 'string', Rule::in(['accept', 'reject'])],
            'username' => ['nullable', 'string', 'max:128', 'not_regex:/[\x00-\x1f\x7f]/'],
        ]);

        $accounting->recordPostAuthEvent([
            ...$validated,
            'authenticated_router_id' => (int) $request->attributes->get('authenticated_router_id'),
            'authenticated_peer_ip' => (string) $request->attributes->get('authenticated_peer_ip'),
        ]);

        return response()->noContent();
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
