<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlutterwaveWithdrawalService
{
    public function __construct(private readonly FlutterwaveService $flutterwave)
    {
    }

    public function approve(Transaction $transaction): array
    {
        $claim = DB::transaction(function () use ($transaction): array {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->type !== 'withdrawal' || $locked->status !== 'pending') {
                return ['transaction' => null, 'message' => 'This withdrawal is no longer pending.'];
            }

            $meta = $locked->meta ?? [];
            if (! empty($meta['flutterwave_transfer_id'])) {
                return ['transaction' => null, 'message' => 'This withdrawal has already been submitted to Flutterwave.'];
            }

            if (in_array($meta['flutterwave_transfer_submission'] ?? null, ['submitting', 'unknown'], true)) {
                return ['transaction' => null, 'message' => 'The transfer submission is awaiting reconciliation. Do not retry it yet.'];
            }

            if (! $this->flutterwave->isConfigured()) {
                if (! config('payments.simulation_enabled', false)) {
                    return ['transaction' => null, 'message' => 'Flutterwave is not configured.'];
                }

                $locked->update([
                    'status' => 'completed',
                    'meta' => array_merge($meta, ['flutterwave_transfer_submission' => 'simulated']),
                ]);

                return ['transaction' => null, 'message' => 'Withdrawal approved in payment simulation mode.'];
            }

            $phone = FlutterwaveService::normalizeUgandanPhone((string) $locked->phone);
            if (! $phone) {
                return ['transaction' => null, 'message' => 'The withdrawal destination is not a valid Uganda mobile number.'];
            }

            if ((float) $locked->amount !== (float) round((float) $locked->amount)) {
                return ['transaction' => null, 'message' => 'Flutterwave Uganda payouts require a whole-number UGX amount.'];
            }
            if (strtoupper((string) PlatformSetting::get('currency', 'UGX')) !== 'UGX') {
                return ['transaction' => null, 'message' => 'Flutterwave mobile money payouts are only configured for UGX.'];
            }

            $locked->update([
                'meta' => array_merge($meta, [
                    'flutterwave_transfer_submission' => 'submitting',
                    'flutterwave_transfer_submitted_at' => now()->toIso8601String(),
                ]),
            ]);

            return ['transaction' => $locked->fresh(), 'message' => null, 'phone' => $phone];
        });

        if (! $claim['transaction']) {
            return [
                'ok' => $claim['message'] === 'Withdrawal approved in payment simulation mode.',
                'message' => $claim['message'],
                'submitted' => false,
            ];
        }

        /** @var Transaction $transaction */
        $transaction = $claim['transaction'];

        try {
            $network = FlutterwaveService::networkForUgandanPhone($claim['phone']);
            $response = $this->flutterwave->createTransfer([
                'account_bank' => $network === 'AIRTEL' ? 'Airtel' : 'MTN',
                'account_number' => $claim['phone'],
                'beneficiary_name' => $transaction->tenant?->contact_name
                    ?: $transaction->tenant?->name
                    ?: 'Wi-Fi Operator',
                'amount' => (int) round((float) $transaction->amount),
                'currency' => 'UGX',
                'narration' => 'Hamnet Wallet Withdrawal',
                'reference' => $transaction->reference,
            ]);
        } catch (Throwable $exception) {
            $this->updateSubmissionState($transaction, 'unknown');
            Log::error('Flutterwave withdrawal submission could not be confirmed.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Flutterwave did not confirm the submission. The request is locked against duplicate payouts while it is reconciled.',
                'submitted' => false,
            ];
        }

        $transfer = $response['data'] ?? null;
        if (($response['status'] ?? null) !== 'success') {
            $this->updateSubmissionState($transaction, 'failed');

            return [
                'ok' => false,
                'message' => 'Flutterwave rejected the transfer request.',
                'submitted' => false,
            ];
        }

        if (! is_array($transfer) || empty($transfer['id'])) {
            $this->updateSubmissionState($transaction, 'unknown');
            Log::error('Flutterwave accepted a withdrawal without returning a transfer ID.', [
                'transaction_id' => $transaction->id,
            ]);

            return [
                'ok' => false,
                'message' => 'Flutterwave accepted the request without a transfer ID. It is locked against duplicate payouts while it is reconciled.',
                'submitted' => false,
            ];
        }

        $transferId = (string) $transfer['id'];
        $this->recordTransfer($transaction, $transferId, $transfer['status'] ?? 'NEW');

        try {
            $verified = $this->flutterwave->verifyTransfer($transferId)['data'] ?? [];
            $result = $this->reconcile($transaction, $transferId, $verified);
        } catch (Throwable $exception) {
            Log::warning('Unable to verify newly submitted Flutterwave transfer.', [
                'transaction_id' => $transaction->id,
                'transfer_id' => $transferId,
                'exception' => $exception::class,
            ]);

            return [
                'ok' => true,
                'message' => 'Flutterwave accepted the transfer. Its completion is awaiting verification.',
                'submitted' => true,
            ];
        }

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'submitted' => true,
        ];
    }

    public function handleWebhook(array $data): array
    {
        $reference = $data['reference'] ?? null;
        $transferId = isset($data['id']) ? (string) $data['id'] : null;

        if (! is_string($reference) || $reference === '' || ! $transferId) {
            return ['ok' => false, 'message' => 'Transfer reference or ID is missing.'];
        }

        $transaction = Transaction::query()
            ->where('type', 'withdrawal')
            ->where('reference', $reference)
            ->first();

        if (! $transaction) {
            return ['ok' => true, 'message' => 'No matching withdrawal.'];
        }

        if (($transaction->meta['flutterwave_transfer_id'] ?? null) !== $transferId) {
            Log::warning('Flutterwave transfer webhook ID did not match the recorded transfer.', [
                'transaction_id' => $transaction->id,
                'transfer_id' => $transferId,
            ]);

            return ['ok' => false, 'message' => 'Transfer ID does not match the withdrawal.'];
        }

        try {
            $verified = $this->flutterwave->verifyTransfer($transferId)['data'] ?? [];
        } catch (Throwable $exception) {
            Log::error('Unable to verify Flutterwave transfer webhook.', [
                'transaction_id' => $transaction->id,
                'transfer_id' => $transferId,
                'exception' => $exception::class,
            ]);

            return ['ok' => false, 'message' => 'Transfer verification is temporarily unavailable.'];
        }

        return $this->reconcile($transaction, $transferId, $verified);
    }

    private function reconcile(Transaction $transaction, string $transferId, array $transfer): array
    {
        if ((string) ($transfer['id'] ?? '') !== $transferId
            || ($transfer['reference'] ?? null) !== $transaction->reference
            || (float) ($transfer['amount'] ?? -1) !== (float) $transaction->amount
            || strtoupper((string) ($transfer['currency'] ?? '')) !== 'UGX') {
            Log::error('Flutterwave transfer verification did not match the withdrawal.', [
                'transaction_id' => $transaction->id,
                'transfer_id' => $transferId,
            ]);

            return ['ok' => false, 'message' => 'Verified transfer details do not match the withdrawal.'];
        }

        $status = strtoupper((string) ($transfer['status'] ?? ''));
        if (! in_array($status, ['SUCCESSFUL', 'FAILED'], true)) {
            return ['ok' => true, 'message' => 'Transfer is still processing.'];
        }

        return DB::transaction(function () use ($transaction, $transferId, $status): array {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status !== 'pending'
                || ($locked->meta['flutterwave_transfer_id'] ?? null) !== $transferId) {
                return ['ok' => true, 'message' => 'Withdrawal has already been processed.'];
            }

            if ($status === 'SUCCESSFUL') {
                $locked->update([
                    'status' => 'completed',
                    'meta' => array_merge($locked->meta ?? [], ['flutterwave_transfer_status' => 'SUCCESSFUL']),
                ]);

                return ['ok' => true, 'message' => 'Withdrawal transfer completed successfully.'];
            }

            $locked->update([
                'status' => 'failed',
                'meta' => array_merge($locked->meta ?? [], ['flutterwave_transfer_status' => 'FAILED']),
            ]);
            Tenant::query()->whereKey($locked->tenant_id)->increment('wallet_balance', $locked->amount);

            return ['ok' => true, 'message' => 'Flutterwave transfer failed; the withdrawal was refunded to the operator wallet.'];
        });
    }

    private function recordTransfer(Transaction $transaction, string $transferId, string $status): void
    {
        DB::transaction(function () use ($transaction, $transferId, $status): void {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $locked->update([
                'meta' => array_merge($locked->meta ?? [], [
                    'flutterwave_transfer_id' => $transferId,
                    'flutterwave_transfer_submission' => 'submitted',
                    'flutterwave_transfer_status' => strtoupper($status),
                ]),
            ]);
        });
    }

    private function updateSubmissionState(Transaction $transaction, string $state): void
    {
        DB::transaction(function () use ($transaction, $state): void {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $locked->update([
                'meta' => array_merge($locked->meta ?? [], ['flutterwave_transfer_submission' => $state]),
            ]);
        });
    }
}
