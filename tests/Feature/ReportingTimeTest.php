<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use App\Support\ReportingTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Production stores timestamps in UTC while operators live in East Africa
 * (UTC+3). phpunit.xml runs the suite in Africa/Kampala, which would hide
 * every boundary bug, so these tests store in UTC like production does.
 */
class ReportingTimeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $operator;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        PlatformSetting::set('timezone', 'Africa/Kampala');

        Role::findOrCreate('operator_admin', 'web');
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator->assignRole('operator_admin');
        $this->package = Package::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Daily', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        URL::defaults(['tenant' => 'acme']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('Africa/Kampala'); // phpunit.xml's APP_TIMEZONE
        parent::tearDown();
    }

    /** Local = UTC+3. 22:00 UTC on 10 March is 01:00 on 11 March in Kampala. */
    private function atLocalOneAmOnTheEleventh(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 22:00:00', 'UTC'));
    }

    private function redeemed(string $utcTime, string $code, ?float $snapshot = null): Voucher
    {
        $batch = VoucherBatch::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'batch_code' => 'B-1'],
            ['package_id' => $this->package->id, 'quantity' => 5]
        );

        return Voucher::create([
            'tenant_id' => $this->tenant->id, 'voucher_batch_id' => $batch->id, 'package_id' => $this->package->id,
            'code' => $code, 'status' => 'redeemed', 'redeemed_at' => $utcTime, 'redeemed_price' => $snapshot,
        ]);
    }

    // ── The helper ──────────────────────────────────────────────────────────

    public function test_local_day_boundaries_are_converted_to_storage_time(): void
    {
        $this->atLocalOneAmOnTheEleventh();

        [$start, $end] = ReportingTime::dayRange();

        $this->assertSame('2026-03-10 21:00:00', $start->format('Y-m-d H:i:s'), 'midnight in Kampala is 21:00 UTC');
        $this->assertSame('2026-03-11 20:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_week_and_month_follow_the_local_calendar(): void
    {
        $this->atLocalOneAmOnTheEleventh();   // Wednesday 11 March (local)

        [$weekStart] = ReportingTime::weekRange();
        [$monthStart, $monthEnd] = ReportingTime::monthRange();

        $this->assertSame('2026-03-08 21:00:00', $weekStart->format('Y-m-d H:i:s'));   // Monday 9 March 00:00 local
        $this->assertSame('2026-02-28 21:00:00', $monthStart->format('Y-m-d H:i:s'));  // 1 March 00:00 local
        $this->assertSame('2026-03-31 20:59:59', $monthEnd->format('Y-m-d H:i:s'));
    }

    public function test_an_unknown_timezone_setting_falls_back_safely(): void
    {
        PlatformSetting::set('timezone', 'Mars/Olympus_Mons');

        $this->assertSame('UTC', ReportingTime::timezone());
    }

    // ── The dashboard ───────────────────────────────────────────────────────

    public function test_todays_revenue_uses_the_operators_day_not_the_utc_day(): void
    {
        $this->atLocalOneAmOnTheEleventh();
        $this->redeemed('2026-03-10 21:30:00', 'AFTER-MIDNIGHT');   // 00:30 on the 11th, local: today
        $this->redeemed('2026-03-10 20:30:00', 'BEFORE-MIDNIGHT');  // 23:30 on the 10th, local: yesterday

        $this->actingAs($this->operator)
            ->get(route('operator.dashboard'))
            ->assertOk()
            ->assertViewHas('todayRevenue', 1000.0)
            ->assertViewHas('weeklyRevenue', 2000.0)
            ->assertViewHas('monthlyRevenue', 2000.0);
    }

    public function test_the_chart_groups_by_local_day(): void
    {
        $this->atLocalOneAmOnTheEleventh();
        $this->redeemed('2026-03-10 21:30:00', 'A');
        $this->redeemed('2026-03-10 20:30:00', 'B');
        $this->redeemed('2026-03-10 09:00:00', 'C');

        $response = $this->actingAs($this->operator)->get(route('operator.dashboard'))->assertOk();
        $chart = collect($response->viewData('chartData'))->keyBy('date');

        $this->assertCount(7, $chart);
        $this->assertSame(1000.0, $chart['2026-03-11']['revenue']);
        $this->assertSame(2000.0, $chart['2026-03-10']['revenue']);
        $this->assertSame(2, $chart['2026-03-10']['transactions']);
        $this->assertSame('2026-03-05', $chart->keys()->first());
    }

    // ── Price snapshot ──────────────────────────────────────────────────────

    public function test_repricing_a_package_does_not_rewrite_past_revenue(): void
    {
        $this->atLocalOneAmOnTheEleventh();
        $this->redeemed('2026-03-10 21:30:00', 'OLD-PRICE', snapshot: 500);   // sold when it cost 500
        $this->redeemed('2026-03-10 21:40:00', 'LEGACY');                      // before snapshots existed
        $this->package->update(['price' => 900]);

        $this->actingAs($this->operator)
            ->get(route('operator.dashboard'))
            ->assertViewHas('todayRevenue', 1400.0);   // 500 (snapshot) + 900 (legacy falls back to the current price)
    }

    public function test_redeeming_records_the_price_at_that_moment(): void
    {
        $batch = VoucherBatch::create(['tenant_id' => $this->tenant->id, 'package_id' => $this->package->id, 'batch_code' => 'B-2', 'quantity' => 1]);
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id, 'voucher_batch_id' => $batch->id,
            'package_id' => $this->package->id, 'code' => 'SNAP-1', 'status' => 'unused',
        ]);

        app(TenantContext::class)->runAs($this->tenant, fn () => app(VoucherActivationService::class)
            ->activate($this->tenant, $voucher, Request::create('/'), '0771234567'));
        $this->package->update(['price' => 5000]);

        $this->assertEquals(1000, (float) $voucher->fresh()->redeemed_price);
    }

    // ── "This month" counters ───────────────────────────────────────────────

    public function test_new_this_month_ignores_the_same_month_of_earlier_years(): void
    {
        $this->atLocalOneAmOnTheEleventh();
        $make = fn (string $phone, string $created) => tap(Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => $phone, 'phone' => $phone, 'status' => 'active',
        ]), fn (Customer $c) => $c->forceFill(['created_at' => $created])->saveQuietly());

        $make('0771000001', '2026-03-02 09:00:00');   // this month
        $make('0771000002', '2025-03-05 09:00:00');   // March of LAST year: used to be counted
        $make('0771000003', '2026-02-27 09:00:00');   // last month

        $this->actingAs($this->operator)
            ->get(route('operator.customers.index'))
            ->assertOk()
            ->assertViewHas('newThisMonth', 1);
    }
}
