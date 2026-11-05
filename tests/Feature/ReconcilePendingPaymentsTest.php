<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcilePendingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_old_pending_mobile_money_transactions(): void
    {
        $transaction = $this->createPendingTransaction();
        $this->mock(PaymentService::class, function ($mock) use ($transaction): void {
            $mock->shouldReceive('reconcilePendingPayment')
                ->once()
                ->withArgs(fn (Transaction $candidate): bool => $candidate->is($transaction))
                ->andReturn('completed');
        });

        $this->artisan('payments:reconcile')
            ->expectsOutput('Checked 1 pending payment(s); 0 provider check(s) were unavailable.')
            ->assertSuccessful();
    }

    public function test_it_returns_failure_when_provider_reconciliation_is_unavailable(): void
    {
        $transaction = $this->createPendingTransaction();
        $this->mock(PaymentService::class, function ($mock) use ($transaction): void {
            $mock->shouldReceive('reconcilePendingPayment')
                ->once()
                ->withArgs(fn (Transaction $candidate): bool => $candidate->is($transaction))
                ->andReturn('unavailable');
        });

        $this->artisan('payments:reconcile')
            ->expectsOutput('Checked 1 pending payment(s); 1 provider check(s) were unavailable.')
            ->assertExitCode(1);
    }

    private function createPendingTransaction(): Transaction
    {
        $tenant = Tenant::create(['name' => 'Payment reconciliation', 'slug' => 'payment-reconciliation']);

        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'status' => 'pending',
            'reference' => 'RECONCILE-001',
            'provider_reference' => 'provider-001',
            'meta' => ['payment_provider' => 'MTN'],
        ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(6)])->save();

        return $transaction;
    }
}
