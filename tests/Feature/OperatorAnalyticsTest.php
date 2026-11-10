<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stored like production (UTC) while the operator lives in UTC+3.
 * "Now" is 01:00 on 11 March in Kampala (22:00 UTC on the 10th).
 */
class OperatorAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Package $daily;
    private Package $weekly;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        PlatformSetting::set('timezone', 'Africa/Kampala');
        $this->travelTo(Carbon::parse('2026-03-10 22:00:00', 'UTC'));

        foreach (['operator_admin', 'operator_finance', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->admin = $this->userWith('operator_admin');
        $this->daily = $this->package($this->tenant, 'Daily', 1000);
        $this->weekly = $this->package($this->tenant, 'Weekly', 5000);
        URL::defaults(['tenant' => 'acme']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('Africa/Kampala'); // phpunit.xml's APP_TIMEZONE
        parent::tearDown();
    }

    private function userWith(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['tenant_id' => ($tenant ?? $this->tenant)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function package(Tenant $tenant, string $name, float $price): Package
    {
        return Package::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'price' => $price, 'currency' => 'UGX', 'duration_value' => 1,
            'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
    }

    private function redeemed(Package $package, string $utc, ?string $phone, ?float $snapshot = null, string $status = 'redeemed'): Voucher
    {
        $batch = VoucherBatch::firstOrCreate(
            ['tenant_id' => $package->tenant_id, 'batch_code' => 'A-1'],
            ['package_id' => $package->id, 'quantity' => 99]
        );

        return Voucher::create([
            'tenant_id' => $package->tenant_id, 'voucher_batch_id' => $batch->id, 'package_id' => $package->id,
            'code' => 'AN-'.uniqid(), 'status' => $status, 'redeemed_at' => $utc, 'redeemed_by' => $phone, 'redeemed_price' => $snapshot,
        ]);
    }

    private function reminderAt(string $utc, string $recipient, string $status = 'sent'): void
    {
        $sms = SmsMessage::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'type' => 'expiry_reminder', 'recipient' => $recipient, 'status' => $status,
        ]);
        DB::table('sms_messages')->where('id', $sms->id)->update(['created_at' => $utc]);
    }

    private function seedSales(): void
    {
        $this->redeemed($this->daily, '2026-03-10 21:30:00', '0771000001');          // 00:30 on the 11th (local), hour 0
        $this->redeemed($this->daily, '2026-03-10 09:00:00', '+256771000001');       // 12:00 on the 10th: same customer, other format
        $this->redeemed($this->weekly, '2026-03-09 09:30:00', '0772000002', 3000);   // snapshot 3000, not the current 5000
        $this->redeemed($this->daily, '2026-03-10 10:00:00', '0773000003', null, 'voided');   // refunded: not revenue
        $this->redeemed($this->daily, '2026-01-01 09:00:00', '0774000004');          // outside 30 days, inside 90
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $this->redeemed($this->package($other, 'Daily', 1000), '2026-03-10 09:00:00', '0775000005');   // another business
    }

    private function page(int|string|null $days = null)
    {
        return $this->actingAs($this->admin)->get(route('operator.analytics').($days ? "?days={$days}" : ''));
    }

    // ── Totals ──────────────────────────────────────────────────────────────

    public function test_totals_cover_only_this_businesss_redeemed_sales_in_the_period(): void
    {
        $this->seedSales();

        $this->page()->assertOk()
            ->assertSee('Sales analytics')
            ->assertViewHas('days', 30)
            ->assertViewHas('revenue', 5000.0)        // 1000 + 1000 + 3000 (snapshot)
            ->assertViewHas('count', 3)
            ->assertViewHas('customers', 2);          // the two formats of 0771000001 are one person
    }

    public function test_a_longer_period_includes_older_sales(): void
    {
        $this->seedSales();

        $this->page(90)->assertViewHas('days', 90)->assertViewHas('count', 4)->assertViewHas('revenue', 6000.0);
    }

    public function test_the_shortest_period_and_unknown_values(): void
    {
        $this->seedSales();

        $this->page(7)->assertViewHas('days', 7)->assertViewHas('count', 3);
        $this->page(45)->assertViewHas('days', 30);
        $this->page('abc')->assertViewHas('days', 30);
    }

    // ── Local time ──────────────────────────────────────────────────────────

    public function test_days_and_hours_follow_the_operators_clock(): void
    {
        $this->seedSales();
        $view = $this->page()->assertOk();

        $daily = collect($view->viewData('daily'))->keyBy('label');
        $this->assertSame(1000.0, $daily['11 Mar']['revenue']);   // 00:30 local on the 11th (21:30 UTC on the 10th)
        $this->assertSame(1000.0, $daily['10 Mar']['revenue']);
        $this->assertSame(3000.0, $daily['09 Mar']['revenue']);

        $hours = $view->viewData('hours');
        $this->assertSame(1, $hours[0], '00:30 local');
        $this->assertSame(2, $hours[12], '12:00 and 12:30 local');
        $this->assertSame(3, array_sum($hours));
        $this->assertCount(30, $daily);
    }

    // ── Customers and packages ──────────────────────────────────────────────

    public function test_repeat_customers_are_counted_across_phone_formats(): void
    {
        $this->seedSales();

        $this->page()->assertViewHas('repeatBuyers', 1)->assertViewHas('repeatRate', 50);
    }

    public function test_packages_are_ranked_by_revenue(): void
    {
        $this->seedSales();
        $packages = $this->page()->viewData('packages');

        $this->assertSame(['Weekly', 'Daily'], array_keys($packages));
        $this->assertSame(['count' => 1, 'revenue' => 3000.0], $packages['Weekly']);
        $this->assertSame(['count' => 2, 'revenue' => 2000.0], $packages['Daily']);
    }

    public function test_an_empty_business_still_renders(): void
    {
        $this->page()->assertOk()
            ->assertViewHas('revenue', 0.0)
            ->assertViewHas('repeatRate', 0)
            ->assertSee('No sales in this period.')
            ->assertSee('No reminders sent in this period');
    }

    // ── Did the SMS reminder bring them back? ───────────────────────────────

    public function test_reminders_are_credited_when_the_customer_buys_again_within_a_day(): void
    {
        $this->seedSales();
        $this->reminderAt('2026-03-10 08:00:00', '+256771000001');   // bought at 09:00 the same day: returned
        $this->reminderAt('2026-03-09 05:00:00', '+256772000002');    // bought 09:30: returned (4.5 h later)
        $this->reminderAt('2026-03-09 08:00:00', '+256774000004');    // no purchase within a day
        $this->reminderAt('2026-03-10 08:00:00', '+256771000001', 'failed');   // failed sends are not counted

        $this->assertSame(['sent' => 3, 'returned' => 2, 'rate' => 67], $this->page()->viewData('reminders'));
    }

    public function test_a_purchase_before_the_reminder_or_a_day_later_does_not_count(): void
    {
        $this->redeemed($this->daily, '2026-03-05 07:00:00', '0771000001');   // before the reminder
        $this->redeemed($this->daily, '2026-03-07 09:01:00', '0772000002');   // 24 h + 1 min after it
        $this->reminderAt('2026-03-05 08:00:00', '+256771000001');
        $this->reminderAt('2026-03-06 09:00:00', '+256772000002');

        $this->assertSame(['sent' => 2, 'returned' => 0, 'rate' => 0], $this->page()->viewData('reminders'));
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_finance_staff_can_see_analytics_but_support_staff_cannot(): void
    {
        $this->actingAs($this->userWith('operator_finance'))->get(route('operator.analytics'))->assertOk();
        $this->actingAs($this->userWith('operator_support'))->get(route('operator.analytics'))->assertForbidden();
    }

    public function test_another_business_never_sees_these_numbers(): void
    {
        $this->seedSales();
        $other = Tenant::create(['name' => 'Gamma', 'slug' => 'gamma', 'status' => 'active']);
        URL::defaults(['tenant' => 'gamma']);

        $this->actingAs($this->userWith('operator_admin', $other))->get(route('operator.analytics'))
            ->assertOk()->assertViewHas('revenue', 0.0)->assertViewHas('count', 0);
    }
}
