<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ProvisioningStuck;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A customer who has paid must not be left without access in silence.
 * This watches sessions stuck in "provisioning": it retries failed activation
 * commands when the site agent is healthy, alerts the operator once, gives up
 * cleanly when the package has already expired, and compensates delays.
 */
class ProvisioningWatchdog
{
    private const REASONS = [
        'agent_not_responding' => 'The on-site agent has not completed the activation yet (it may be offline).',
        'agent_unhealthy' => 'The activation failed and the site agent is offline.',
        'retries_exhausted' => 'The activation failed repeatedly, even after automatic retries.',
        'no_command' => 'No activation command exists and a new one could not be created (is the router assigned to a site with a paired agent?).',
        'expired' => 'The package expired before access was ever activated.',
    ];

    public function __construct(
        private readonly SiteAgentCommandService $commands,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return string waiting|requeued|recreated|alerted|already_alerted|expired|skipped
     */
    public function inspect(Session $session): string
    {
        $tenant = Tenant::find($session->tenant_id);
        if (! $tenant || $session->status !== 'provisioning') {
            return 'skipped';
        }

        return $this->context->runAs($tenant, fn () => $this->evaluate($session));
    }

    private function evaluate(Session $session): string
    {
        $command = SiteAgentCommand::query()
            ->where('session_id', $session->id)
            ->where('operation', 'provision_user')
            ->first();

        if ($session->expires_at && $session->expires_at->lte(now())) {
            return $this->giveUp($session);
        }

        if (! $command) {
            return $this->recreate($session);
        }

        $age = $this->minutesSince($session->started_at ?? $session->created_at);

        if ($command->status === 'failed') {
            $agent = $command->agent;
            $healthy = $agent && ! $agent->revoked_at && $agent->last_seen_at
                && $agent->last_seen_at->gte(now()->subMinutes((int) config('provisioning.agent_healthy_minutes')));
            $canRequeue = $session->provisioning_requeues < (int) config('provisioning.max_requeues');

            if ($healthy && $canRequeue) {
                $backedOff = $command->updated_at->lte(now()->subMinutes((int) config('provisioning.requeue_backoff_minutes')));

                return $backedOff ? $this->requeue($session, $command) : 'waiting';
            }

            return $this->alert($session, $canRequeue ? 'agent_unhealthy' : 'retries_exhausted', $age);
        }

        return $age >= (int) config('provisioning.alert_after_minutes')
            ? $this->alert($session, 'agent_not_responding', $age)
            : 'waiting';
    }

    private function requeue(Session $session, SiteAgentCommand $command): string
    {
        DB::transaction(function () use ($session, $command): void {
            $this->commands->requeueProvision($command, $session);
            Session::withoutTenancy()->whereKey($session->id)->increment('provisioning_requeues');
        });

        AuditLog::record('provisioning.requeued', $session, [
            'attempt' => $session->provisioning_requeues + 1,
            'previous_failure' => $command->failure_reason,
        ]);

        return 'requeued';
    }

    private function recreate(Session $session): string
    {
        $router = $session->router_id ? Router::withoutTenancy()->find($session->router_id) : null;
        $voucher = $session->voucher_id ? Voucher::withoutTenancy()->find($session->voucher_id) : null;

        if ($router && $voucher) {
            try {
                $this->commands->provision($router, $session, $voucher);
                AuditLog::record('provisioning.recreated', $session);

                return 'recreated';
            } catch (Throwable $exception) {
                Log::warning('Unable to recreate the activation command.', [
                    'session_id' => $session->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $this->alert($session, 'no_command', $this->minutesSince($session->started_at ?? $session->created_at));
    }

    /**
     * The package ran out before access was ever active. Close the session,
     * stop any activation still in flight, and make the agent remove a router
     * user that may already have been created.
     */
    private function giveUp(Session $session): string
    {
        $closed = DB::transaction(function () use ($session): bool {
            // Lock order matches SiteAgentController::complete: command, then session.
            SiteAgentCommand::query()
                ->where('session_id', $session->id)
                ->where('operation', 'provision_user')
                ->whereIn('status', ['pending', 'claimed'])
                ->update([
                    'status' => 'failed',
                    'failure_reason' => 'Session expired before activation completed.',
                    'claimed_at' => null,
                ]);

            $locked = Session::withoutTenancy()->lockForUpdate()->find($session->id);
            if (! $locked || $locked->status !== 'provisioning') {
                return false;
            }

            $locked->update(['status' => 'expired']);

            return true;
        });

        if (! $closed) {
            return 'skipped';
        }

        $router = $session->router_id ? Router::withoutTenancy()->find($session->router_id) : null;
        $voucher = $session->voucher_id ? Voucher::withoutTenancy()->find($session->voucher_id) : null;
        if ($router && $voucher) {
            try {
                $this->commands->expire($router, $session, $voucher);
            } catch (Throwable $exception) {
                Log::warning('Unable to queue cleanup for an unprovisioned session.', [
                    'session_id' => $session->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        AuditLog::record('provisioning.expired_unprovisioned', $session, [
            'package_id' => $session->package_id,
        ]);
        Log::error('Paid session expired without ever being provisioned.', ['session_id' => $session->id]);
        $this->notifyOperators($session, 'expired', $this->minutesSince($session->started_at ?? $session->created_at), true);

        return 'expired';
    }

    /**
     * Alert at most once per session (atomic claim, safe under concurrency).
     */
    private function alert(Session $session, string $reason, int $minutes): string
    {
        $claimed = Session::withoutTenancy()
            ->whereKey($session->id)
            ->whereNull('provisioning_alerted_at')
            ->update(['provisioning_alerted_at' => now()]);

        if (! $claimed) {
            return 'already_alerted';
        }

        Log::error('Paid session is stuck in provisioning.', [
            'session_id' => $session->id,
            'reason' => $reason,
            'minutes' => $minutes,
        ]);
        AuditLog::record('provisioning.stuck', $session, ['reason' => $reason, 'minutes' => $minutes]);
        $this->notifyOperators($session, $reason, $minutes, false);

        return 'alerted';
    }

    private function notifyOperators(Session $session, string $reason, int $minutes, bool $expired): void
    {
        try {
            $admins = User::withoutTenancy()
                ->where('tenant_id', $session->tenant_id)
                ->role('operator_admin')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            $package = $session->package_id ? Package::withoutTenancy()->find($session->package_id) : null;
            $router = $session->router_id ? Router::withoutTenancy()->find($session->router_id) : null;

            $url = null;
            try {
                $slug = Tenant::whereKey($session->tenant_id)->value('slug');
                $url = $slug ? route('operator.sessions.index', ['tenant' => $slug]) : null;
            } catch (Throwable) {
                // A missing link must never block the alert.
            }

            Notification::send($admins, new ProvisioningStuck(
                $session->id,
                self::REASONS[$reason] ?? $reason,
                $minutes,
                $package?->name,
                $router?->name,
                $expired,
                $url,
            ));
        } catch (Throwable $exception) {
            Log::error('Unable to notify operators about a stuck session.', [
                'session_id' => $session->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Called by the site-agent API when router access has been confirmed.
     * Runs inside the command-completion transaction.
     */
    public function markProvisioned(SiteAgentCommand $job): void
    {
        $session = Session::withoutTenancy()
            ->where('tenant_id', $job->tenant_id)
            ->lockForUpdate()
            ->find($job->session_id);

        // Never revive a session that was already closed (e.g. expired unprovisioned).
        if (! $session || $session->status !== 'provisioning') {
            return;
        }

        $updates = ['status' => 'active', 'provisioned_at' => now()];

        $delay = $this->minutesSince($session->started_at ?? $session->created_at);
        $package = $session->package_id
            ? Package::withoutTenancy()->where('tenant_id', $session->tenant_id)->find($session->package_id)
            : null;

        if (config('provisioning.compensate_delay') && $package && $delay > (int) config('provisioning.grace_minutes')) {
            $updates['expires_at'] = $package->expiresAt(now());
        }

        $session->update($updates);

        if (isset($updates['expires_at'])) {
            AuditLog::record('provisioning.delay_compensated', $session, [
                'delay_minutes' => $delay,
                'new_expires_at' => $updates['expires_at']->toIso8601String(),
            ]);
        }
    }

    private function minutesSince($moment): int
    {
        return $moment ? max(0, (int) floor($moment->diffInMinutes(now()))) : 0;
    }
}
