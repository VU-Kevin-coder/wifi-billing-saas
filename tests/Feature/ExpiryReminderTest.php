<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    private const SMS_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    private Tenant $tenant;

    /** The fake gateway answers according to this flag (a second Http::fake would be ignored). */
    private bool $gatewayUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        PlatformSetting::set('sms_provider', 'africastalking');
        PlatformSetting::set('sms_username', 'sandbox');
        PlatformSetting::set('sms_api_key', 'test-sms-key');
        PlatformSetting::set('sms_environment', 'sandbox');
        $this->gatewayAccepts();

        $this->tenant = $this->makeTenant('acme');
    }

    private function gatewayAccepts(): void
    {
        Http::fake([self::SMS_URL => fn () => $this->gatewayUp
            ? Http::response([
                'SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [['status' => 'Success', 'number' => '+256771234567', 'cost' => 'UGX 20']]],
            ], 201)
            : Http::response(['errorMessage' => 'gateway down'], 500)]);
    }

    private function makeTenant(string $slug, array $settings = []): Tenant
    {
        $tenant = Tenant::create(['name' => ucfirst($slug).' WiFi', 'slug' => $slug, 'status' => 'active']);
        $tenant->update(['settings' => array_merge($tenant->settings, [
            'sms_enabled' => true, 'notify_expiry_sms_reminder' => true, 'notify_expiry_reminder_mins' => 30,
        ], $settings)]);

        return $tenant->fresh();
    }

    private function createSession(?string $phone, int $minutesLeft, int $totalMinutes = 120, ?Tenant $tenant = null, string $status = 'active'): Session
    {
        $tenant ??= $this->tenant;
        $package = Package::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Daily'], [
            'price' => 1000, 'currency' => 'UGX', 'duration_value' => 2, 'duration_unit' => 'hours',
            'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        $batch = VoucherBatch::withoutTenancy()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'batch_code' => 'R-1'],
            ['package_id' => $package->id, 'quantity' => 50]
        );
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $package->id,
            'code' => 'RM-'.uniqid(), 'status' => 'redeemed', 'redeemed_by' => $phone, 'redeemed_at' => now()->subMinutes($totalMinutes - $minutesLeft),
        ]);

        return Session::withoutTenancy()->create([
            'tenant_id' => $tenant->id, 'voucher_id' => $voucher->id, 'package_id' => $package->id, 'status' => $status,
            'started_at' => now()->subMinutes($totalMinutes - $minutesLeft), 'expires_at' => now()->addMinutes($minutesLeft),
        ]);
    }

    private function remind(): void
    {
        $this->artisan('sessions:remind')->assertSuccessful();
    }

    private function sentSms(): int
    {
        return Http::recorded(fn (ClientRequest $r) => $r->url() === self::SMS_URL)->count();
    }

    // ── Who gets a reminder, and when ───────────────────────────────────────

    public function test_a_customer_near_expiry_gets_one_reminder_with_their_renew_link(): void
    {
        $session = $this->createSession('0771234567', minutesLeft: 20);

        $this->remind();

        $this->assertSame(1, $this->sentSms());
        $fresh = $session->fresh();
        $this->assertNotNull($fresh->expiry_reminder_sent_at);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $fresh->renew_token);

        $log = SmsMessage::withoutTenancy()->sole();
        $this->assertSame(['expiry_reminder', 'sent', '+256771234567', $session->id], [$log->type, $log->status, $log->recipient, $log->session_id]);

        Http::assertSent(function (ClientRequest $r) use ($fresh) {
            $body = $r['message'];

            return $r['to'] === '+256771234567'
                && str_contains($body, 'Acme WiFi')
                && str_contains($body, 'Daily')
                && str_contains($body, '20 minutes')
                && str_contains($body, '/portal/r/'.$fresh->renew_token);
        });
    }

    public function test_nobody_is_reminded_twice(): void
    {
        $this->createSession('0771234567', minutesLeft: 20);

        $this->remind();
        $this->remind();
        $this->remind();

        $this->assertSame(1, $this->sentSms());
        $this->assertSame(1, SmsMessage::withoutTenancy()->count());
    }

    public function test_only_sessions_inside_the_reminder_window_are_reminded(): void
    {
        $this->createSession('0771000001', minutesLeft: 120, totalMinutes: 300);   // plenty left
        $this->createSession('0771000002', minutesLeft: 20);                        // due
        $this->createSession('0771000003', minutesLeft: -5, totalMinutes: 120);     // already expired
        $this->createSession('0771000004', minutesLeft: 20, status: 'expired');     // not active

        $this->remind();

        $this->assertSame(1, $this->sentSms());
        $this->assertSame('+256771000002', SmsMessage::withoutTenancy()->sole()->recipient);
    }

    public function test_the_window_follows_the_businesss_setting(): void
    {
        $this->tenant->update(['settings' => array_merge($this->tenant->settings, ['notify_expiry_reminder_mins' => 60])]);
        $this->createSession('0771234567', minutesLeft: 45, totalMinutes: 300);

        $this->remind();

        $this->assertSame(1, $this->sentSms(), '45 minutes left is inside a 60 minute window');
    }

    public function test_customers_without_a_phone_number_are_skipped(): void
    {
        $this->createSession(null, minutesLeft: 20);
        $this->createSession('Walk-in', minutesLeft: 20);

        $this->remind();

        $this->assertSame(0, $this->sentSms());
    }

    public function test_a_package_shorter_than_the_window_is_not_reminded_at_purchase(): void
    {
        $this->createSession('0771234567', minutesLeft: 25, totalMinutes: 30);   // a 30 minute package, window 30

        $this->remind();

        $this->assertSame(0, $this->sentSms());
    }

    public function test_businesses_that_have_not_opted_in_send_nothing(): void
    {
        $this->tenant->update(['settings' => array_merge($this->tenant->settings, ['sms_enabled' => false])]);
        $quiet = $this->makeTenant('quiet', ['notify_expiry_sms_reminder' => false]);
        $this->createSession('0771000001', minutesLeft: 20);
        $this->createSession('0771000002', minutesLeft: 20, tenant: $quiet);

        $this->remind();

        $this->assertSame(0, $this->sentSms());
    }

    public function test_each_business_is_reminded_with_its_own_name(): void
    {
        $other = $this->makeTenant('beta');
        $this->createSession('0771000001', minutesLeft: 20);
        $this->createSession('0772000002', minutesLeft: 20, tenant: $other);

        $this->remind();

        $this->assertSame(2, $this->sentSms());
        Http::assertSent(fn (ClientRequest $r) => $r['to'] === '+256772000002' && str_contains($r['message'], 'Beta WiFi') && ! str_contains($r['message'], 'Acme'));
    }

    public function test_the_operators_own_template_is_used(): void
    {
        $this->tenant->update(['settings' => array_merge($this->tenant->settings, [
            'template_expiry_reminder' => "Hi from @company_name\nonly @hours_left left. Pay: @portal_url Help: @support_number",
            'support_phone' => '+256700111222',
        ])]);
        $session = $this->createSession('0771234567', minutesLeft: 20);

        $this->remind();

        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r['message'], "Hi from Acme WiFi\nonly 20 minutes left. Pay: ")
            && str_contains($r['message'], 'Help: +256700111222')
            && str_contains($r['message'], $session->fresh()->renew_token));
    }

    // ── Cost control and failures ───────────────────────────────────────────

    public function test_the_monthly_cap_stops_sending_and_leaves_the_rest_unclaimed(): void
    {
        config(['sms.monthly_cap_per_tenant' => 1]);
        $first = $this->createSession('0771000001', minutesLeft: 10);
        $second = $this->createSession('0771000002', minutesLeft: 20);

        $this->artisan('sessions:remind')->expectsOutputToContain('capped=1')->assertSuccessful();

        $this->assertSame(1, $this->sentSms());
        $this->assertNotNull($first->fresh()->expiry_reminder_sent_at);    // the sooner expiry goes first
        $this->assertNull($second->fresh()->expiry_reminder_sent_at, 'a withheld reminder must not be marked as sent');
    }

    public function test_a_gateway_failure_is_logged_and_retried_a_limited_number_of_times(): void
    {
        $this->gatewayUp = false;
        $session = $this->createSession('0771234567', minutesLeft: 20);

        $this->remind();
        $this->assertSame('failed', SmsMessage::withoutTenancy()->latest('id')->first()->status);
        $this->assertNull($session->fresh()->expiry_reminder_sent_at, 'released so the next run retries');

        $this->remind();
        $this->remind();
        $this->assertSame(3, $this->sentSms());
        $this->assertNotNull($session->fresh()->expiry_reminder_sent_at, 'after the third failure it stops');

        $this->remind();
        $this->assertSame(3, $this->sentSms());
    }

    public function test_a_failed_attempt_does_not_count_against_the_monthly_cap(): void
    {
        config(['sms.monthly_cap_per_tenant' => 1]);
        $this->gatewayUp = false;
        $this->createSession('0771234567', minutesLeft: 20);
        $this->remind();

        $this->gatewayUp = true;
        $this->remind();

        $this->assertSame('sent', SmsMessage::withoutTenancy()->latest('id')->first()->status);
    }

    public function test_the_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sessions:remind');
    }

    // ── Template rendering ──────────────────────────────────────────────────

    public function test_templates_replace_known_placeholders_and_leave_unknown_ones_visible(): void
    {
        $text = SmsTemplate::render('@company_name: @package_name ends @expiry_date @mystery', [
            'company_name' => 'Acme', 'package_name' => 'Daily', 'expiry_date' => '10 Mar',
        ]);

        $this->assertSame('Acme: Daily ends 10 Mar @mystery', $text);
    }

    public function test_longer_placeholder_names_win_over_their_prefixes(): void
    {
        $this->assertSame('B / A', SmsTemplate::render('@package_name / @package', ['package' => 'A', 'package_name' => 'B']));
    }

    public function test_values_cannot_break_the_message_layout(): void
    {
        $this->assertSame('Hi Acme Wifi!', SmsTemplate::render('Hi @name!', ['name' => "Acme\n\n  Wifi"]));
    }

    public function test_segment_counting(): void
    {
        $this->assertSame(1, SmsTemplate::segments(str_repeat('a', 160)));
        $this->assertSame(2, SmsTemplate::segments(str_repeat('a', 161)));
        $this->assertSame(3, SmsTemplate::segments(str_repeat('a', 307)));
    }
}
