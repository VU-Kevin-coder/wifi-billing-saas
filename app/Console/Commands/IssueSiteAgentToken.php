<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueSiteAgentToken extends Command
{
    protected $signature = 'site-agent:issue-token {site : Site ID} {--name= : Agent display name}';

    protected $description = 'Pair or rotate the token for a site-local router agent';

    public function handle(): int
    {
        $site = Site::query()->find($this->argument('site'));
        if (! $site) {
            $this->components->error('Site not found.');

            return self::FAILURE;
        }

        $token = Str::random(64);
        SiteAgent::query()->updateOrCreate(
            ['site_id' => $site->id],
            [
                'tenant_id' => $site->tenant_id,
                'name' => $this->option('name') ?: $site->name.' agent',
                'token_hash' => hash('sha256', $token),
                'last_seen_at' => null,
                'revoked_at' => null,
            ],
        );

        $this->components->info('Copy this token into the site agent environment now. It cannot be shown again:');
        $this->line($token);

        return self::SUCCESS;
    }
}
