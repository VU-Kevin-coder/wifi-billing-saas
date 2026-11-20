<?php

namespace App\Jobs;

use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ProcessObPayWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(public array $payload) {}

    public function handle(
        PaymentGatewayService $payments,
        PaymentPayoutService $payouts,
    ): void {
        $event = $this->payload['event'] ?? '';
        $result = str_starts_with($event, 'collection.')
            ? $payments->reconcileCollectionWebhook('obpay', $this->payload)
            : $payouts->handleWebhook('obpay', $this->payload);

        if ($result === 'unavailable') {
            throw new RuntimeException('OBPay transaction verification is temporarily unavailable.');
        }
    }
}
