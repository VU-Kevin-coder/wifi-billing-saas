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
        $smsDays = max(7, (int) PlatformSetting::get('retention.sms', 90));

        $deleted = [
            'audit_logs' => DB::table('audit_logs')
                ->where('created_at', '<', $now->copy()->subDays($auditDays))
                ->delete(),
            'sms_messages' => DB::table('sms_messages')
                ->where('created_at', '<', $now->copy()->subDays($smsDays))
                ->delete(),
            'sessions' => DB::table('sessions_log')
                ->whereIn('status', ['expired', 'disconnected', 'cancelled'])
                ->where('pending_revocation', false)
                ->where('updated_at', '<', $now->copy()->subDays($sessionDays))
                ->delete(),
        ];

        Log::info('Platform data retention sweep completed.', $deleted);
        $this->info('Pruned '.$deleted['audit_logs'].' audit log(s), '.$deleted['sms_messages'].' SMS log(s), and '.$deleted['sessions'].' session(s).');

        return self::SUCCESS;
    }
}
