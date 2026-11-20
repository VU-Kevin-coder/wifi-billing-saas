<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class PaymentPayoutService
{
    public function __construct(
        private readonly ObPayGateway $obpay,
        private readonly PawaPayGateway $pawapay,
        private readonly IotecGateway $iotec,
    ) {}

    public function approve(Transaction $transaction, ?string $gateway, ?string $manualReference = null): array
    {
        $claim = DB::transaction(function () use ($transaction, $gateway, $manualReference): array {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->type !== 'withdrawal' || $locked->status !== 'pending') {
                return ['message' => 'This withdrawal is no longer pending.'];
            }
            if (PlatformSetting::get('payouts.paused', false)) {
                return ['message' => 'Payouts are suspended by the platform.'];
            }

            $meta = $locked->meta ?? [];
            $allowedMethods = (array) PlatformSetting::get(
                'wallet.payout_method',
                ['mtn_momo', 'airtel_money', 'bank_transfer'],
            );
            $method = $meta['payout_method'] ?? (
                $this->networkForPhone($this->normalizeUgandanPhone((string) $locked->phone) ?? '') === 'airtel'
                    ? 'airtel_money'
                    : 'mtn_momo'
            );
            if (! in_array($method, $allowedMethods, true)) {
                return ['message' => 'This payout method is no longer enabled by the platform.'];
            }
            if ($method === 'bank_transfer') {
                return $this->approveBankTransfer($locked, $meta, $manualReference);
            }
            if (! in_array($method, ['mtn_momo', 'airtel_money'], true)) {
                return ['message' => 'This payout method is not supported.'];
            }
            if (! is_string($gateway) || ! in_array($gateway, PaymentGatewaySettings::PROVIDERS, true)) {
                return ['message' => 'Select an available payout gateway.'];
            }
            if (! PaymentGatewaySettings::configured($gateway)) {
                return ['message' => 'The selected payout gateway is not fully configured.'];
            }
            if (! empty($meta['payout_gateway']) && $meta['payout_gateway'] !== $gateway) {
                return ['message' => 'This withdrawal is already assigned to another gateway; reconcile it before changing providers.'];
            }
            if (in_array($meta['payout_submission'] ?? null, ['submitting', 'unknown', 'submitted'], true)) {
                return ['message' => 'This payout may already be in progress. Reconcile it before retrying.'];
            }

            $phone = $this->normalizeUgandanPhone((string) $locked->phone);
            if (! $phone || (float) $locked->amount !== (float) round((float) $locked->amount)
                || strtoupper((string) PlatformSetting::get('currency', 'UGX')) !== 'UGX') {
                return ['message' => 'Mobile-money payouts require a valid Uganda number and a whole-number UGX amount.'];
            }
            if ($gateway === 'iotec' && (float) $locked->amount < 500) {
                return ['message' => 'ioTec mobile-money payouts require a minimum amount of UGX 500.'];
            }
            $network = $this->networkForPhone($phone);
            if (! $network || ($method === 'mtn_momo' && $network !== 'mtn')
                || ($method === 'airtel_money' && $network !== 'airtel')) {
                return ['message' => 'The withdrawal method does not match the recipient phone network.'];
            }

            $providerReference = $meta['payout_provider_reference'] ?? null;
            if (! is_string($providerReference) || $providerReference === '') {
                $providerReference = $gateway === 'pawapay' ? (string) Str::uuid() : $locked->reference;
            }
            $locked->update([
                'meta' => array_merge($meta, [
                    'payout_gateway' => $gateway,
                    'payout_provider_reference' => $providerReference,
                    'payout_submission' => 'submitting',
                    'payout_submitted_at' => now()->toIso8601String(),
                    'payout_network' => $network,
                ]),
            ]);

            return [
                'transaction' => $locked->fresh(),
                'gateway' => $gateway,
                'phone' => $phone,
                'message' => null,
            ];
        });

        if (! isset($claim['transaction'])) {
            return ['ok' => (bool) ($claim['ok'] ?? false), 'message' => $claim['message']];
        }

        /** @var Transaction $transaction */
        $transaction = $claim['transaction'];
        try {
            $response = match ($claim['gateway']) {
                'obpay' => $this->submitObPay($transaction, $claim['phone']),
                'pawapay' => $this->submitPawaPay($transaction, $claim['phone']),
                'iotec' => $this->submitIotec($transaction, $claim['phone']),
            };
        } catch (Throwable $exception) {
            $this->setSubmissionState($transaction, 'unknown');
            Log::error('Payment gateway payout submission could not be confirmed.', [
                'transaction_id' => $transaction->id,
                'provider' => $claim['gateway'],
                'exception' => $exception::class,
            ]);

            return ['ok' => false, 'message' => 'The provider could not confirm submission. This withdrawal is locked against duplicate payouts while it is reconciled.'];
        }

        if ($response['rejected']) {
            $this->setSubmissionState($transaction, 'failed');

            return ['ok' => false, 'message' => 'The selected provider rejected the payout request.'];
        }

        $this->recordSubmission($transaction, $response['provider_transaction_id'] ?? null);
        $result = $this->reconcile($transaction->fresh());
        if ($result === 'unavailable') {
            return ['ok' => true, 'message' => 'The provider accepted the payout. Completion is awaiting authoritative status verification.'];
        }
        if ($result === 'completed') {
            return ['ok' => true, 'message' => 'Payout completed and verified by the provider.'];
        }
        if ($result === 'failed') {
            return ['ok' => true, 'message' => 'The provider confirmed payout failure; funds were returned to the operator wallet.'];
        }
        if ($result === 'mismatch') {
            return ['ok' => false, 'message' => 'The provider response did not match the withdrawal. It is locked for manual review.'];
        }

        return ['ok' => true, 'message' => 'The provider accepted the payout. Its completion is pending.'];
    }

    public function handleWebhook(string $provider, array $payload): string
    {
        $reference = match ($provider) {
            'obpay' => $payload['data']['reference'] ?? null,
            'pawapay' => $payload['payoutId'] ?? null,
            'iotec' => $payload['externalId'] ?? null,
            default => null,
        };
        if (! is_string($reference) || $reference === '') {
            return 'missing_reference';
        }

        $query = Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->where('meta->payout_gateway', $provider);
        $query->where($provider === 'pawapay' ? 'meta->payout_provider_reference' : 'reference', $reference);
        $transaction = $query->first();
        if (! $transaction) {
            return 'ignored';
        }

        return $this->reconcile($transaction);
    }

    public function reconcile(Transaction $transaction): string
    {
        $transaction->refresh();
        $meta = $transaction->meta ?? [];
        $provider = $meta['payout_gateway'] ?? null;
        $reference = $meta['payout_provider_reference'] ?? null;
        if ($transaction->status !== 'pending'
            || ! is_string($provider) || ! in_array($provider, PaymentGatewaySettings::PROVIDERS, true)
            || ! is_string($reference) || $reference === '') {
            return 'pending';
        }

        try {
            $response = match ($provider) {
                'obpay' => $this->obpay->transaction($reference),
                'pawapay' => $this->pawapay->payoutStatus($reference),
                'iotec' => $this->iotec->disbursementStatusByExternalId($transaction->reference),
            };
        } catch (Throwable $exception) {
            Log::warning('Unable to verify an operator payout.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return 'unavailable';
        }

        $details = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $status = strtoupper((string) ($details['status'] ?? $details['transactionStatus'] ?? ''));
        $returnedReferenceMatches = match ($provider) {
            'obpay' => ($details['reference'] ?? null) === $reference,
            'pawapay' => ($details['payoutId'] ?? null) === $reference
                && ($details['clientReferenceId'] ?? null) === $transaction->reference,
            'iotec' => ($details['externalId'] ?? null) === $transaction->reference
                && is_string($details['id'] ?? null)
                && (($meta['payout_provider_transaction_id'] ?? null) === null
                    || ($details['id'] ?? null) === $meta['payout_provider_transaction_id']),
        };
        $returnedAmount = $details['amount'] ?? null;
        $currency = strtoupper((string) ($details['currency'] ?? $details['currencyCode'] ?? ''));
        if (! $returnedReferenceMatches
            || ! is_numeric($returnedAmount)
            || (float) $returnedAmount !== (float) $transaction->amount
            || $currency !== 'UGX') {
            Log::error('Verified payout details did not match the local withdrawal.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
            ]);
            $this->markManualReview($transaction, 'Verified provider payout details did not match the withdrawal.');

            return 'mismatch';
        }

        if ($provider === 'iotec' && is_string($details['id'] ?? null)
            && ($meta['payout_provider_transaction_id'] ?? null) !== $details['id']) {
            $this->recordSubmission($transaction, $details['id']);
            $transaction->refresh();
        }

        if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'COMMITTED', 'SUCCEEDED'], true)) {
            return $this->finalize($transaction, 'completed', $status);
        }
        if (in_array($status, ['FAILED', 'REJECTED', 'REVERSED', 'CANCELLED'], true)) {
            return $this->finalize($transaction, 'failed', $status);
        }

        return 'pending';
    }

    private function submitPawaPay(Transaction $transaction, string $phone): array
    {
        $prediction = $this->pawapay->predictProvider($phone);
        $provider = $prediction['provider'] ?? null;
        $canonicalPhone = $prediction['phoneNumber'] ?? null;
        if (! is_string($provider) || ! is_string($canonicalPhone) || $canonicalPhone === '') {
            return ['rejected' => true];
        }
        $response = $this->pawapay->payout(
            (string) $transaction->meta['payout_provider_reference'],
            $transaction->reference,
            (int) $transaction->amount,
            $canonicalPhone,
            $provider,
        );
        $status = $response['status'] ?? null;
        if (in_array($status, ['REJECTED', 'FAILED', 'INVALID_REQUEST'], true)) {
            return ['rejected' => true];
        }
        if (! in_array($status, ['ACCEPTED', 'DUPLICATE_IGNORED'], true)) {
            throw new RuntimeException('PawaPay returned an unrecognized payout submission state.');
        }
        if (($response['payoutId'] ?? null) !== $transaction->meta['payout_provider_reference']) {
            throw new RuntimeException('PawaPay accepted a payout without matching its payout ID.');
        }

        return ['rejected' => false, 'provider_transaction_id' => $response['payoutId']];
    }

    private function submitObPay(Transaction $transaction, string $phone): array
    {
        $response = $this->obpay->payout(
            $transaction->reference,
            (int) $transaction->amount,
            $phone,
            $transaction->tenant?->contact_name ?: $transaction->tenant?->name ?: 'Wi-Fi Operator',
            $this->obpay->callbackUrl(),
        );
        $data = $response['data'] ?? [];
        if (($response['success'] ?? false) === false) {
            return ['rejected' => true];
        }
        if (($response['success'] ?? false) !== true
            || ($data['reference'] ?? null) !== $transaction->reference
            || strtolower((string) ($data['status'] ?? '')) !== 'pending') {
            throw new RuntimeException('OBPay did not confirm a matching pending payout.');
        }

        return ['rejected' => false, 'provider_transaction_id' => $transaction->reference];
    }

    private function submitIotec(Transaction $transaction, string $phone): array
    {
        $response = $this->iotec->disburse(
            $transaction->reference,
            (int) $transaction->amount,
            $phone,
            $transaction->tenant?->contact_name ?: $transaction->tenant?->name ?: 'Wi-Fi Operator',
        );
        $id = $response['id'] ?? null;
        $status = strtoupper((string) ($response['status'] ?? ''));
        if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'], true)) {
            return ['rejected' => true];
        }
        if (! is_string($id) || $id === '' || ($response['externalId'] ?? null) !== $transaction->reference
            || ! in_array($status, ['PENDING', 'SENTTOVENDOR', 'SUCCESS', 'AWAITINGAPPROVAL'], true)) {
            throw new RuntimeException('ioTec did not confirm a matching payout request.');
        }

        return ['rejected' => false, 'provider_transaction_id' => $id];
    }

    private function approveBankTransfer(Transaction $transaction, array $meta, ?string $reference): array
    {
        if (blank($reference) || blank($meta['bank_details_encrypted'] ?? null)) {
            return ['message' => 'Record the completed bank transfer reference and verify the saved bank details before approval.'];
        }
        try {
            $details = json_decode(Crypt::decryptString($meta['bank_details_encrypted']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            Log::error('Unable to verify encrypted bank payout details before approval.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception::class,
            ]);

            return ['message' => 'Bank account details could not be verified; this withdrawal needs manual review.'];
        }
        if (! is_array($details) || blank($details['bank_name'] ?? null)
            || blank($details['account_name'] ?? null) || blank($details['account_number'] ?? null)) {
            return ['message' => 'Bank account details are incomplete; this withdrawal needs manual review.'];
        }

        $transaction->update([
            'status' => 'completed',
            'meta' => array_merge($meta, [
                'manual_payout_reference' => trim($reference),
                'manual_payout_completed_at' => now()->toIso8601String(),
            ]),
        ]);

        return ['ok' => true, 'message' => 'Manual bank transfer recorded and withdrawal approved.'];
    }

    private function finalize(Transaction $transaction, string $outcome, string $providerStatus): string
    {
        return DB::transaction(function () use ($transaction, $outcome, $providerStatus): string {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status !== 'pending') {
                return $locked->status;
            }

            $locked->update([
                'status' => $outcome,
                'meta' => array_merge($locked->meta ?? [], ['payout_provider_status' => $providerStatus]),
            ]);
            if ($outcome === 'failed') {
                app(WalletService::class)->apply(
                    $locked->tenant_id,
                    $locked->amount,
                    WalletService::WITHDRAWAL_REFUND,
                    $locked,
                    'Withdrawal failed at the payment provider; funds returned',
                );
            }

            return $outcome;
        });
    }

    private function recordSubmission(Transaction $transaction, ?string $providerTransactionId): void
    {
        DB::transaction(function () use ($transaction, $providerTransactionId): void {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status !== 'pending') {
                return;
            }
            $meta = $locked->meta ?? [];
            if ($providerTransactionId !== null) {
                $meta['payout_provider_transaction_id'] = $providerTransactionId;
            }
            $meta['payout_submission'] = 'submitted';
            $locked->update(['meta' => $meta]);
        });
    }

    private function setSubmissionState(Transaction $transaction, string $state): void
    {
        DB::transaction(function () use ($transaction, $state): void {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status === 'pending') {
                $locked->update([
                    'meta' => array_merge($locked->meta ?? [], ['payout_submission' => $state]),
                ]);
            }
        });
    }

    private function markManualReview(Transaction $transaction, string $reason): void
    {
        DB::transaction(function () use ($transaction, $reason): void {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status === 'pending') {
                $locked->update([
                    'meta' => array_merge($locked->meta ?? [], [
                        'needs_manual_review' => true,
                        'manual_review_reason' => $reason,
                    ]),
                ]);
            }
        });
        Log::error('Payment gateway payout needs manual review.', [
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'reason' => $reason,
        ]);
    }

    private function normalizeUgandanPhone(string $phone): ?string
    {
        $number = preg_replace('/\D+/', '', $phone);
        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            $number = '256'.substr($number, 1);
        } elseif (strlen($number) === 9) {
            $number = '256'.$number;
        }

        return preg_match('/^256\d{9}$/', $number) ? $number : null;
    }

    private function networkForPhone(string $phone): ?string
    {
        return match (substr($phone, 3, 2)) {
            '70', '74', '75' => 'airtel',
            '76', '77', '78', '79' => 'mtn',
            default => null,
        };
    }
}
