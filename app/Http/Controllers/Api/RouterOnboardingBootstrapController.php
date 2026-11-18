<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RouterOnboardingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RouterOnboardingBootstrapController extends Controller
{
    public function __invoke(Request $request, RouterOnboardingService $onboarding): Response
    {
        $token = $request->input('token');
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
}
