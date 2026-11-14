<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneRetainedData extends Command
{
    protected $signature = 'platform:prune-retained-data';

    protected $description = 'Delete platform data beyond its configured retention period';

    public function handle(): int
    {
        $now = now();
        $auditDays = max(90, (int) PlatformSetting::get('audit.retention', 730));
        $sessionDays = max(30, (int) PlatformSetting::get('retention.sessions', 180));
        $commandDays = max(7, (int) PlatformSetting::get('retention.commands', 30));
        $smsDays = max(7, (int) PlatformSetting::get('retention.sms', 90));

        $deleted = [
            'audit_logs' => DB::table('audit_logs')
                ->where('created_at', '<', $now->copy()->subDays($auditDays))
                ->delete(),
            'site_agent_commands' => DB::table('site_agent_commands')
                ->whereIn('status', ['completed', 'failed'])
                ->where('updated_at', '<', $now->copy()->subDays($commandDays))
                ->delete(),
            'sms_messages' => DB::table('sms_messages')
                ->where('created_at', '<', $now->copy()->subDays($smsDays))
                ->delete(),
            'sessions' => DB::table('sessions_log')
                ->whereIn('status', ['expired', 'disconnected', 'cancelled'])
                ->where('pending_revocation', false)
                ->where('updated_at', '<', $now->copy()->subDays($sessionDays))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('site_agent_commands')
                        ->whereColumn('site_agent_commands.session_id', 'sessions_log.id')
                        ->whereIn('site_agent_commands.status', ['pending', 'claimed']);
                })
                ->delete(),
        ];

        Log::info('Platform data retention sweep completed.', $deleted);
        $this->info('Pruned '.$deleted['audit_logs'].' audit log(s), '.$deleted['site_agent_commands'].' command(s), '.$deleted['sms_messages'].' SMS log(s), and '.$deleted['sessions'].' session(s).');

        return self::SUCCESS;
    }
}
