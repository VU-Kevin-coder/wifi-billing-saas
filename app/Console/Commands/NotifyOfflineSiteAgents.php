<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Models\SiteAgent;
use App\Models\User;
use App\Notifications\SiteAgentOffline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyOfflineSiteAgents extends Command
{
    protected $signature = 'site-agents:notify-offline';

    protected $description = 'Notify administrators about site agents that have stopped checking in';

    public function handle(): int
    {
        if (! PlatformSetting::get('alerts.notify_agent_offline', true)) {
            return self::SUCCESS;
        }

        $thresholdMinutes = max(5, (int) PlatformSetting::get('alerts.agent_offline_after', 15));
        $cutoff = now()->subMinutes($thresholdMinutes);
        $platformEmails = array_values(array_filter(
            (array) PlatformSetting::get('alerts.platform_emails', []),
            fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL),
        ));
        $notified = 0;

        SiteAgent::query()
            ->whereNull('revoked_at')
            ->whereNull('offline_notified_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_seen_at', '<=', $cutoff)
                    ->orWhere(function ($neverSeen) use ($cutoff): void {
                        $neverSeen->whereNull('last_seen_at')->where('created_at', '<=', $cutoff);
                    });
            })
            ->with(['site:id,name', 'tenant:id,name'])
            ->orderBy('id')
            ->chunkById(100, function ($agents) use (&$notified, $platformEmails): void {
                foreach ($agents as $agent) {
                    $admins = User::withoutTenancy()
                        ->where('tenant_id', $agent->tenant_id)
                        ->whereNull('deactivated_at')
                        ->role('operator_admin')
                        ->get();
                    if ($admins->isEmpty() && $platformEmails === []) {
                        Log::warning('Offline site agent has no alert recipients.', ['site_agent_id' => $agent->id]);

                        continue;
                    }

                    $claimed = SiteAgent::query()
                        ->whereKey($agent->id)
                        ->whereNull('revoked_at')
                        ->whereNull('offline_notified_at')
                        ->update(['offline_notified_at' => now()]);
                    if (! $claimed) {
                        continue;
                    }

                    $site = $agent->site;
                    $tenant = $agent->tenant;
                    $lastSeen = $agent->last_seen_at ?? $agent->created_at;
                    $url = route('platform.configuration.control', ['section' => 'health']);
                    $notification = new SiteAgentOffline(
                        (int) $agent->id,
                        $agent->name,
                        $site?->name ?? 'Unknown site',
                        $tenant?->name ?? 'Unknown workspace',
                        $lastSeen?->toDateTimeString(),
                        $url,
                    );

                    try {
                        Notification::send($admins, $notification);
                        foreach ($platformEmails as $email) {
                            Notification::route('mail', $email)->notify($notification);
                        }
                        $notified++;
                    } catch (Throwable $exception) {
                        Log::error('Unable to deliver an offline site-agent alert.', [
                            'site_agent_id' => $agent->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        $this->info("Notified {$notified} offline site agent(s) after {$thresholdMinutes} minute(s).");

        return self::SUCCESS;
    }
}
