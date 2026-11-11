<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Services\ProvisioningWatchdog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteAgentController extends Controller
{
    public function poll(Request $request): JsonResponse
    {
        /** @var SiteAgent $agent */
        $agent = $request->attributes->get('site_agent');
        $commands = DB::transaction(function () use ($agent): array {
            $agent->forceFill(['last_seen_at' => now()])->save();
            $rows = SiteAgentCommand::query()
                ->where('site_agent_id', $agent->id)
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($stale): void {
                            $stale->where('status', 'claimed')
                                ->where('claimed_at', '<=', now()->subMinutes(2));
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(20)
                ->get();

            foreach ($rows as $command) {
                if ($command->attempts >= 5) {
                    $command->update(['status' => 'failed']);
                    if (in_array($command->operation, ['telemetry', 'test_connection'], true)) {
                        $this->markRouterOffline($agent, $command);
                    }

                    continue;
                }

                $command->update([
                    'status' => 'claimed',
                    'attempts' => $command->attempts + 1,
                    'claimed_at' => now(),
                ]);
            }

            return $rows
                ->where('status', 'claimed')
                ->where('attempts', '<=', 5)
                ->map(fn (SiteAgentCommand $command): array => [
                    'id' => $command->id,
                    'operation' => $command->operation,
                    'router_id' => $command->router_id,
                    'payload' => $command->payload,
                ])
                ->values()
                ->all();
        });

        return response()->json(['commands' => $commands]);
    }

    public function complete(Request $request, int $command): JsonResponse
    {
        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
            'telemetry' => ['sometimes', 'nullable', 'array'],
            'telemetry.cpu_usage' => ['required_with:telemetry', 'integer', 'between:0,100'],
            'telemetry.memory_usage' => ['required_with:telemetry', 'integer', 'between:0,100'],
            'telemetry.active_users' => ['required_with:telemetry', 'integer', 'min:0'],
            'telemetry.uptime' => ['required_with:telemetry', 'string', 'max:64'],
        ]);
        /** @var SiteAgent $agent */
        $agent = $request->attributes->get('site_agent');

        $result = DB::transaction(function () use ($agent, $command, $validated): array {
            $job = SiteAgentCommand::query()
                ->where('site_agent_id', $agent->id)
                ->lockForUpdate()
                ->findOrFail($command);

            if ($job->status === 'completed') {
                return ['duplicate' => true, 'status' => 'completed'];
            }

            abort_unless($job->status === 'claimed', 409, 'Command is not currently claimed by this agent.');

            if (! $validated['success']) {
                $job->update([
                    'status' => $job->attempts >= 5 ? 'failed' : 'pending',
                    'claimed_at' => null,
                    'failure_reason' => $validated['message'] ?? 'Agent reported command failure.',
                ]);
                if (in_array($job->operation, ['telemetry', 'test_connection'], true) && $job->attempts >= 5) {
                    $this->markRouterOffline($agent, $job);
                }

                return ['duplicate' => false, 'status' => $job->status];
            }

            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
                'claimed_at' => null,
                'failure_reason' => null,
            ]);

            if ($job->operation === 'telemetry') {
                abort_unless(isset($validated['telemetry']), 422, 'Telemetry command did not include router metrics.');
                $this->routerForAgent($agent, $job)->update([
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'telemetry_at' => now(),
                    'cpu_usage' => $validated['telemetry']['cpu_usage'],
                    'memory_usage' => $validated['telemetry']['memory_usage'],
                    'active_users' => $validated['telemetry']['active_users'],
                    'uptime' => $validated['telemetry']['uptime'],
                ]);
            } elseif ($job->operation === 'test_connection') {
                $this->routerForAgent($agent, $job)->update([
                    'status' => 'online',
                    'last_seen_at' => now(),
                ]);
            } elseif ($job->operation === 'expire_user') {
                Session::withoutTenancy()
                    ->where('tenant_id', $job->tenant_id)
                    ->whereKey($job->session_id)
                    ->update(['status' => 'expired']);
            } elseif ($job->operation === 'provision_user') {
                app(ProvisioningWatchdog::class)->markProvisioned($job);
            }

            return ['duplicate' => false, 'status' => 'completed'];
        });

        return response()->json($result);
    }

    private function markRouterOffline(SiteAgent $agent, SiteAgentCommand $command): void
    {
        $this->routerForAgent($agent, $command)->update(['status' => 'offline']);
    }

    private function routerForAgent(SiteAgent $agent, SiteAgentCommand $command): Router
    {
        return Router::withoutTenancy()
            ->where('tenant_id', $agent->tenant_id)
            ->findOrFail($command->router_id);
    }
}
