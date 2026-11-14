<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Router;
use App\Services\RadiusRouterCredentialService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class RadiusRouterCredentialController extends Controller
{
    public function store(
        Request $request,
        string $tenantSlug,
        int $router,
        TenantContext $context,
        RadiusRouterCredentialService $credentials,
    ): Response {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();
        abort_unless($tenant && $tenantId && $tenant->slug === $tenantSlug, 404, 'Tenant not found');

        $validator = Validator::make($request->all(), [
            'nas_identifier' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'confirm_radius_mode' => ['accepted'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withHeaders($this->noStoreHeaders());
        }

        $result = DB::transaction(function () use ($credentials, $tenant, $tenantId, $router, $validator): array {
            $routerModel = Router::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($router);

            if ($routerModel->getRawOriginal('radius_shared_secret') !== null) {
                return ['status' => Response::HTTP_CONFLICT, 'content' => null];
            }

            $nasIdentifier = $validator->validated()['nas_identifier'];
            $secret = $credentials->optIn($routerModel, $nasIdentifier);

            AuditLog::record('router.radius_credential_provisioned', $routerModel, [
                'nas_identifier' => $routerModel->radius_nas_identifier,
            ]);

            return [
                'status' => Response::HTTP_OK,
                'content' => view('operator.routers.radius-credential', [
                    'tenant' => $tenant,
                    'router' => $routerModel,
                    'secret' => $secret,
                ])->render(),
            ];
        });

        $content = $result['content'] ?? 'A RADIUS credential already exists for this router. It cannot be retrieved again.';

        return response($content, $result['status'])
            ->withHeaders($this->noStoreHeaders());
    }

    /**
     * The one-time response must not be stored by browsers or intermediaries.
     *
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
