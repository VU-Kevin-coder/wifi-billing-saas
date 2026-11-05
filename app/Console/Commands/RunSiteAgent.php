<?php

namespace App\Console\Commands;

use App\Services\SiteAgentRouterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class RunSiteAgent extends Command
{
    protected $signature = 'site-agent:work {--url= : SaaS application base URL} {--token= : Site agent bearer token}';

    protected $description = 'Poll the SaaS over TLS and provision local MikroTik routers';

    public function handle(SiteAgentRouterService $routers): int
    {
        $baseUrl = rtrim((string) ($this->option('url') ?: env('SITE_AGENT_URL')), '/');
        $token = (string) ($this->option('token') ?: env('SITE_AGENT_TOKEN'));
        if (! str_starts_with($baseUrl, 'https://') || $token === '') {
            $this->components->error('Configure SITE_AGENT_URL with https:// and set SITE_AGENT_TOKEN.');

            return self::FAILURE;
        }

        $http = Http::acceptJson()->withToken($token)->timeout(30);
        $this->components->info('Site agent connected; polling for router commands.');

        while (true) {
            try {
                $response = $http->get($baseUrl.'/api/site-agent/commands')->throw();
                $commands = $response->json('commands');
                if (! is_array($commands)) {
                    throw new \UnexpectedValueException('Site-agent poll returned an invalid command list.');
                }

                if ($commands === []) {
                    sleep(3);
                    continue;
                }

                foreach ($commands as $command) {
                    $success = false;
                    $message = null;
                    try {
                        $routers->execute(
                            (int) ($command['router_id'] ?? 0),
                            (string) ($command['operation'] ?? ''),
                            is_array($command['payload'] ?? null) ? $command['payload'] : [],
                        );
                        $success = true;
                    } catch (Throwable $exception) {
                        $message = $exception::class.': '.$exception->getMessage();
                        $this->components->error("Command {$command['id']} failed: {$message}");
                    }

                    $http->post($baseUrl.'/api/site-agent/commands/'.(int) $command['id'].'/complete', [
                        'success' => $success,
                        'message' => $message,
                    ])->throw();
                }
            } catch (Throwable $exception) {
                $this->components->error('Site-agent request failed: '.$exception::class);
                sleep(5);
            }
        }

        return self::SUCCESS;
    }
}
