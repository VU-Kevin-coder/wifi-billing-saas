<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_only_new_gateway_withdrawals_and_leaves_legacy_pending_for_manual_handling(): void
    {
        $tenant = Tenant::create(['name' => 'Reconciliation operator', 'slug' => 'reconciliation-operator']);
        $newWithdrawal = $this->withdrawal($tenant->id, 'WTH-NEW-001', [
            'payout_gateway' => 'pawapay',
            'payout_provider_reference' => 'payout-id',
            'payout_submission' => 'submitted',
        ]);
        $legacyWithdrawal = $this->withdrawal($tenant->id, 'WTH-OLD-001', [
            'flutterwave_transfer_submission' => 'unknown',
        ]);
        $this->mock(PaymentPayoutService::class, function ($mock) use ($newWithdrawal): void {
            $mock->shouldReceive('reconcile')
                ->once()
                ->withArgs(fn (Transaction $transaction): bool => $transaction->is($newWithdrawal))
                ->andReturn('pending');
        });

        $this->artisan('withdrawals:reconcile', ['--minutes' => 0])
            ->expectsOutput('Reconciled withdrawals: pending=1')
            ->assertSuccessful();

        $this->assertSame('pending', $legacyWithdrawal->fresh()->status);
    }

    private function withdrawal(int $tenantId, string $reference, array $meta): Transaction
    {
        return Transaction::create([
            'tenant_id' => $tenantId,
            'type' => 'withdrawal',
            'amount' => 10000,
            'status' => 'pending',
            'reference' => $reference,
            'phone' => '0771234567',
            'meta' => $meta,
        ]);
    }
}
