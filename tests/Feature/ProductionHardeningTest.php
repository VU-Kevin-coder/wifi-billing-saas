<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the deployment configuration against silent regressions, and runs the
 * real preflight script that decides whether a production container may boot.
 */
class ProductionHardeningTest extends TestCase
{
    private const GOOD = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:test',
        'APP_URL' => 'https://wifi.example.com',
        'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_DRIVER' => 'redis',
        'TENANT_BASE_DOMAIN' => 'wifi.example.com',
        'SITE_AGENT_REQUIRED' => 'true',
        'MAIL_MAILER' => 'smtp',
        'TRUSTED_PROXIES' => '127.0.0.1',
    ];

    private function shellBinary(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            foreach ([
                'C:\\Program Files\\Git\\bin\\bash.exe',
                'C:\\Program Files\\Git\\usr\\bin\\sh.exe',
                'C:\\Program Files (x86)\\Git\\bin\\bash.exe',
            ] as $candidate) {
                if (file_exists($candidate)) {
                    return '"'.$candidate.'"';
                }
            }
        }

        return 'sh';
    }

    private function preflight(array $overrides, string $role = 'web')
    {
        $sh = $this->shellBinary();
        $env = array_merge(self::GOOD, $overrides);

        // An empty string unsets nothing in the child, so drop keys set to null.
        return Process::path(base_path())
            ->env(array_filter($env, fn ($v) => $v !== null))
            ->run("{$sh} scripts/preflight.sh {$role}");
    }

    public function test_preflight_passes_with_safe_production_settings(): void
    {
        $result = $this->preflight([]);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringContainsString('Preflight OK', $result->output());
    }

    public function test_preflight_is_skipped_outside_production(): void
    {
        $this->assertTrue($this->preflight(['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])->successful());
    }

    #[DataProvider('unsafeSettings')]
    public function test_preflight_refuses_unsafe_production_settings(array $overrides, string $expected): void
    {
        $result = $this->preflight($overrides);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString($expected, $result->errorOutput());
    }

    public static function unsafeSettings(): array
    {
        return [
            'debug enabled' => [['APP_DEBUG' => 'true'], 'APP_DEBUG must be false'],
            'plain http url' => [['APP_URL' => 'http://wifi.example.com'], 'APP_URL must start with https'],
            'cookie not secure' => [['SESSION_SECURE_COOKIE' => 'false'], 'SESSION_SECURE_COOKIE must be true'],
            'default base domain' => [['TENANT_BASE_DOMAIN' => 'localhost'], 'TENANT_BASE_DOMAIN'],
            'site agent disabled' => [['SITE_AGENT_REQUIRED' => 'false'], 'SITE_AGENT_REQUIRED'],
            'array sessions' => [['SESSION_DRIVER' => 'array'], 'SESSION_DRIVER=array'],
            'missing app key' => [['APP_KEY' => ''], 'APP_KEY is not set'],
            'wildcard trusted proxies' => [['TRUSTED_PROXIES' => '*'], 'must not trust every source'],
            'missing trusted proxies' => [['TRUSTED_PROXIES' => ''], 'must contain the exact trusted proxy'],
        ];
    }

    public function test_preflight_only_warns_for_file_sessions_and_log_mailer(): void
    {
        $result = $this->preflight(['SESSION_DRIVER' => 'file', 'MAIL_MAILER' => 'log']);

        $this->assertTrue($result->successful());
        $this->assertStringContainsString('SESSION_DRIVER=file', $result->errorOutput());
        $this->assertStringContainsString('MAIL_MAILER=log', $result->errorOutput());
    }

    public function test_worker_preflight_does_not_need_web_only_settings(): void
    {
        $result = $this->preflight(
            ['APP_URL' => null, 'SESSION_SECURE_COOKIE' => null, 'TENANT_BASE_DOMAIN' => null],
            role: 'worker'
        );

        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    public function test_blueprint_runs_the_scheduler_as_its_own_worker_with_shared_state(): void
    {
        $yaml = file_get_contents(base_path('render.yaml'));

        $this->assertStringNotContainsString('plan: free', $yaml);
        $this->assertStringContainsString('type: worker', $yaml);
        $this->assertStringContainsString('schedule:work', $yaml);
        $this->assertStringContainsString('type: keyvalue', $yaml);
        $this->assertStringContainsString('fromGroup: hamnet-shared', $yaml);
        $this->assertMatchesRegularExpression('/key: SESSION_DRIVER\s+value: redis/', $yaml);
        $this->assertMatchesRegularExpression('/key: SESSION_SECURE_COOKIE\s+value: "true"/', $yaml);
        $this->assertMatchesRegularExpression('/key: SITE_AGENT_REQUIRED\s+value: "true"/', $yaml);
        $this->assertStringContainsString('key: TRUSTED_PROXIES', $yaml);
        $this->assertStringNotContainsString('value: "*"', $yaml);
        $this->assertSame(2, substr_count($yaml, 'key: MAIL_MAILER'), 'web and scheduler must both configure mail');
        $this->assertStringContainsString('key: MAIL_FROM_NAME', $yaml);
        $this->assertSame(2, substr_count($yaml, 'ipAllowList: []'), 'database and key-value must not be public');
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringNotContainsString('evilfreelancer/docker-routeros:latest', $compose);
        $this->assertStringContainsString('127.0.0.1:8728:8728', $compose);
        $this->assertStringContainsString('127.0.0.1:2222:22', $compose);
    }

    public function test_web_container_no_longer_hosts_the_scheduler(): void
    {
        $this->assertStringNotContainsString(
            'schedule:work',
            file_get_contents(base_path('docker/supervisor/supervisord.render.conf'))
        );
    }

    public function test_nginx_sets_hardening_headers(): void
    {
        $conf = file_get_contents(base_path('docker/nginx/render.conf'));

        foreach (['Strict-Transport-Security', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy-Report-Only', 'server_tokens off'] as $needle) {
            $this->assertStringContainsString($needle, $conf);
        }
    }

    public function test_production_image_builds_assets_excludes_dev_dependencies_and_runs_preflight(): void
    {
        $docker = file_get_contents(base_path('Dockerfile.render'));

        $this->assertStringContainsString('npm run build', $docker);
        $this->assertStringContainsString('COPY --from=assets /app/public/build', $docker);
        $this->assertStringContainsString('--no-dev', $docker);
        $this->assertStringContainsString('preflight.sh', $docker);
        $this->assertStringContainsString('preflight.sh web', file_get_contents(base_path('scripts/00-laravel-deploy.sh')));
    }
}
