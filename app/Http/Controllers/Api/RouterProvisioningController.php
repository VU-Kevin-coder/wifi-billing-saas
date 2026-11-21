<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RouterBootstrapEnrollmentService;
use App\Services\RouterOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class RouterProvisioningController extends Controller
{
    public function bootstrap(Request $request, RouterOnboardingService $onboarding): Response
    {
        $token = $request->query('token');
        if (! is_string($token)) {
            abort(404);
        }

        $script = $onboarding->fetchBootstrapScript($token);
        if ($script === null) {
            abort(404);
        }

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function enroll(Request $request, RouterBootstrapEnrollmentService $enrollment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
            'serial' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'model' => ['required', 'string', 'max:128', 'not_regex:/[\x00-\x1f\x7f]/'],
            'api_ca_certificate' => ['required', 'string', 'max:16384'],
        ]);

        if ($validator->fails()) {
            Log::error('ENROLL VALIDATION FAILED: ', [
                'errors' => $validator->errors()->toArray(),
                'payload_keys' => array_keys($request->all()),
            ]);

            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {
            $enrollment->enroll(
                $validated['token'],
                $validated['serial'],
                $validated['model'],
                $validated['api_ca_certificate'],
            );
        } catch (RuntimeException $exception) {
            Log::warning('ROUTER ENROLLMENT REJECTED', [
                'reason' => $exception->getMessage(),
                'payload_keys' => array_keys($request->all()),
            ]);

            return response()->json([
                'errors' => ['token' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json(['status' => 'success'], 200, [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
