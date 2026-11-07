<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WithdrawalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.flutterwave.com/v3';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-key');
        PlatformSetting::set('currency', 'UGX');

        // Balance already reflects the debit taken when the withdrawal was requested.
        $this->tenant = Tenant::create([
            'name' => 'Operator',
            'slug' => 'operator',
            'status' => 'active',
            'wallet_balance' => 40000,
        ]);
    }

    private function withdrawal(array $meta, string $reference = 'WTH-REC-001'): Transaction
    {
        return Transaction::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => $reference,
            'phone' => '0771234567',
            'meta' => $meta,
        ]);
    }

    private function transfer(string $reference, string $status, int $id = 9933, int $amount = 10000): array
    {
        return [
            'id' => $id,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'UGX',
            'status' => $status,
        ];
    }

    private function fakeProvider(array $listed, ?array $verified): void
    {
        $fakes = [
            self::API.'/transfers?*' => Http::response(['status' => 'success', 'data' => $listed]),
        ];
        if ($verified) {
            $fakes[self::API.'/transfers/'.$verified['id']] = Http::response(['status' => 'success', 'data' => $verified]);
        }
        Http::fake($fakes);
    }

    private function service(): FlutterwaveWithdrawalService
    {
        return app(FlutterwaveWithdrawalService::class);
    }

    private function balance(): float
    {
        return (float) $this->tenant->fresh()->wallet_balance;
    }

    public function test_unknown_submission_that_succeeded_at_the_provider_is_completed(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $t = $this->transfer($tx->reference, 'SUCCESSFUL');
        $this->fakeProvider([$t], $t);

        $result = $this->service()->reconcileStuck($tx);

        $this->assertSame('completed', $result['state']);
        $fresh = $tx->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('9933', $fresh->meta['flutterwave_transfer_id']);
        $this->assertEquals(40000, $this->balance(), 'a successful payout must not refund the wallet');
        $this->assertSame(1, AuditLog::where('action', 'withdrawal.reconciled')->count());
    }

    public function test_unknown_submission_that_failed_at_the_provider_is_refunded_exactly_once(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $t = $this->transfer($tx->reference, 'FAILED');
        $this->fakeProvider([$t], $t);

        $this->assertSame('failed', $this->service()->reconcileStuck($tx)['state']);
        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertEquals(50000, $this->balance());

        $this->assertSame('skipped', $this->service()->reconcileStuck($tx)['state']);
        $this->assertEquals(50000, $this->balance(), 'second run must not refund again');
    }

    public function test_known_transfer_id_still_processing_stays_pending(): void
    {
        $tx = $this->withdrawal([
            'flutterwave_transfer_id' => '9933',
            'flutterwave_transfer_submission' => 'submitted',
        ]);
        $t = $this->transfer($tx->reference, 'NEW');
        $this->fakeProvider([], $t);

        $this->assertSame('processing', $this->service()->reconcileStuck($tx)['state']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertEquals(40000, $this->balance());
    }

    public function test_transfer_not_found_is_never_refunded_or_resubmitted(): void
    {
        $tx = $this->withdrawal([
            'flutterwave_transfer_submission' => 'unknown',
            'flutterwave_transfer_submitted_at' => now()->subHour()->toIso8601String(),
        ]);
        $this->fakeProvider([], null);

        $result = $this->service()->reconcileStuck($tx);

        $this->assertSame('not_found', $result['state']);
        $fresh = $tx->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, $fresh->meta['reconcile_not_found_count']);
        $this->assertArrayNotHasKey('needs_manual_review', $fresh->meta);
        $this->assertEquals(40000, $this->balance());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_transfer_not_found_after_the_review_window_is_flagged_once(): void
    {
        $tx = $this->withdrawal([
            'flutterwave_transfer_submission' => 'unknown',
            'flutterwave_transfer_submitted_at' => now()->subHours(25)->toIso8601String(),
        ]);
        $this->fakeProvider([], null);

        $this->service()->reconcileStuck($tx);
        $this->service()->reconcileStuck($tx);

        $this->assertTrue($tx->fresh()->meta['needs_manual_review']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertEquals(40000, $this->balance());
        $this->assertSame(1, AuditLog::where('action', 'withdrawal.needs_manual_review')->count());
    }

    public function test_provider_record_with_a_different_amount_is_flagged_and_not_applied(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $t = $this->transfer($tx->reference, 'SUCCESSFUL', 9933, 99999);
        $this->fakeProvider([$t], $t);

        $this->assertSame('manual_review', $this->service()->reconcileStuck($tx)['state']);
        $fresh = $tx->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertArrayNotHasKey('flutterwave_transfer_id', $fresh->meta);
        $this->assertTrue($fresh->meta['needs_manual_review']);
    }

    public function test_list_results_for_a_different_reference_are_ignored(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $other = $this->transfer('WTH-SOMEONE-ELSE', 'SUCCESSFUL', 7777);
        $this->fakeProvider([$other], $other);

        $this->assertSame('not_found', $this->service()->reconcileStuck($tx)['state']);
        $this->assertSame('pending', $tx->fresh()->status);
    }

    public function test_provider_outage_changes_nothing(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        Http::fake([self::API.'/transfers?*' => Http::response([], 500)]);

        $this->assertSame('unavailable', $this->service()->reconcileStuck($tx)['state']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertEquals(40000, $this->balance());
    }

    public function test_unsubmitted_withdrawals_are_left_for_admin_approval(): void
    {
        $tx = $this->withdrawal([]);
        Http::fake();

        $this->assertSame('skipped', $this->service()->reconcileStuck($tx)['state']);
        Http::assertNothingSent();
    }

    public function test_webhook_can_complete_a_withdrawal_whose_transfer_id_was_never_recorded(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $this->fakeProvider([], $this->transfer($tx->reference, 'SUCCESSFUL'));

        $result = $this->service()->handleWebhook(['id' => 9933, 'reference' => $tx->reference]);

        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $tx->fresh()->status);
    }

    public function test_webhook_with_a_mismatching_provider_record_is_not_adopted(): void
    {
        $tx = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        $this->fakeProvider([], $this->transfer('WTH-OTHER', 'SUCCESSFUL'));

        $result = $this->service()->handleWebhook(['id' => 9933, 'reference' => $tx->reference]);

        $this->assertFalse($result['ok']);
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertArrayNotHasKey('flutterwave_transfer_id', $tx->fresh()->meta);
    }

    public function test_webhook_for_an_unsubmitted_withdrawal_is_still_rejected(): void
    {
        $tx = $this->withdrawal([]);
        Http::fake();

        $result = $this->service()->handleWebhook(['id' => 9933, 'reference' => $tx->reference]);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_command_resolves_only_eligible_withdrawals(): void
    {
        $stuck = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown'], 'WTH-STUCK');
        $idle = $this->withdrawal([], 'WTH-IDLE');
        $t = $this->transfer($stuck->reference, 'SUCCESSFUL');
        $this->fakeProvider([$t], $t);

        $this->travel(15)->minutes();
        $this->artisan('withdrawals:reconcile')->assertSuccessful();

        $this->assertSame('completed', $stuck->fresh()->status);
        $this->assertSame('pending', $idle->fresh()->status);
    }

    public function test_command_skips_recently_changed_withdrawals(): void
    {
        $stuck = $this->withdrawal(['flutterwave_transfer_submission' => 'unknown']);
        Http::fake();

        $this->artisan('withdrawals:reconcile')->assertSuccessful();

        $this->assertSame('pending', $stuck->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('withdrawals:reconcile');
    }
}
