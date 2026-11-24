<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\CollectionPurposeRouter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CollectionPurposeRouterTest extends TestCase
{
    private function mapping(): array
    {
        return ['reference' => 'ref-1', 'provider' => 'obpay', 'account' => 'account-1',
            'tenant' => 'tenant-1', 'purpose' => 'voucher', 'target' => 'attempt-1',
            'amount' => 5000, 'currency' => 'UGX'];
    }

    private function payment(): array
    {
        return ['reference' => 'ref-1', 'provider' => 'obpay', 'account' => 'account-1',
            'transaction_id' => 'provider-tx-1', 'status' => 'paid', 'amount' => 5000, 'currency' => 'UGX'];
    }

    public function test_each_known_purpose_routes_only_to_its_configured_handler_after_verification(): void
    {
        foreach (['voucher', 'subscription'] as $purpose) {
            $calls = [];
            $mapping = $this->mapping();
            $mapping['purpose'] = $purpose;
            $mapping['callback_purpose'] = $purpose === 'voucher' ? 'subscription' : 'voucher';
            $payment = $this->payment() + ['purpose' => $mapping['callback_purpose'], 'callback' => 'untrusted'];
            $router = new CollectionPurposeRouter(
                function ($ref) use ($mapping, &$calls) {
                    $calls[] = ['resolve', $ref];

                    return [$mapping];
                },
                function ($provider, $account, $ref) use ($payment, &$calls) {
                    $calls[] = ['verify', $provider, $account, $ref];

                    return $payment;
                },
                [
                    'voucher' => function ($m, $p) use (&$calls) {
                        $calls[] = ['voucher', $m, $p];
                    },
                    'subscription' => function ($m, $p) use (&$calls) {
                        $calls[] = ['subscription', $m, $p];
                    },
                ],
            );
            self::assertSame('routed', $router->route('ref-1', 'obpay', 'account-1', 'tenant-1'));
            self::assertSame(['resolve', 'verify', $purpose], array_column($calls, 0));
            self::assertSame($purpose, $calls[2][1]['purpose']);
            self::assertArrayNotHasKey('callback_purpose', $calls[2][1]);
            self::assertArrayNotHasKey('purpose', $calls[2][2]);
            self::assertArrayNotHasKey('callback', $calls[2][2]);
            self::assertSame('provider-tx-1', $calls[2][2]['transaction_id']);
        }
    }

    public function test_unknown_ambiguous_or_malformed_mappings_never_verify_or_dispatch(): void
    {
        $m = $this->mapping();
        foreach ([[], [$m, $m], [$m, array_replace($m, ['purpose' => 'subscription', 'tenant' => 'tenant-2'])],
            ['not-list' => $m], [null], null, false] as $rows) {
            $verify = 0;
            $calls = 0;
            $router = new CollectionPurposeRouter(fn ($ref) => $rows,
                function () use (&$verify) {
                    $verify++;

                    return $this->payment();
                },
                ['voucher' => function () use (&$calls) {
                    $calls++;
                }, 'subscription' => function () use (&$calls) {
                    $calls++;
                }]);
            self::assertSame('not_routed', $router->route('ref-1', 'obpay', 'account-1', 'tenant-1'));
            self::assertSame(0, $verify);
            self::assertSame(0, $calls);
        }
    }

    public function test_mapping_mismatch_invalid_data_and_missing_handler_fail_closed(): void
    {
        $cases = [
            ['reference' => 'other'], ['provider' => 'other'], ['account' => 'other'], ['tenant' => 'other'],
            ['purpose' => 'subscription'], ['purpose' => 'other'], ['target' => ''], ['target' => "bad\n"],
            ['amount' => '5000'], ['amount' => 0], ['amount' => 5000.0], ['currency' => 'ugx'], ['currency' => 'UGX '],
        ];
        foreach ($cases as $change) {
            $calls = 0;
            $verifications = 0;
            $mapping = array_replace($this->mapping(), $change);
            $router = new CollectionPurposeRouter(fn () => [$mapping],
                function () use (&$verifications) {
                    $verifications++;

                    return $this->payment();
                },
                ['voucher' => function () use (&$calls) {
                    $calls++;
                }]);
            self::assertSame('not_routed', $router->route('ref-1', 'obpay', 'account-1', 'tenant-1'), json_encode($change));
            self::assertSame(0, $calls);
            self::assertSame(0, $verifications);
        }
    }

    public function test_wrong_routing_context_never_dispatches(): void
    {
        $calls = 0;
        $verifications = 0;
        $router = new CollectionPurposeRouter(fn () => [$this->mapping()],
            function () use (&$verifications) {
                $verifications++;

                return $this->payment();
            },
            ['voucher' => function () use (&$calls) {
                $calls++;
            }]);
        foreach ([['other', 'obpay', 'account-1', 'tenant-1'], ['ref-1', 'other', 'account-1', 'tenant-1'],
            ['ref-1', 'obpay', 'other', 'tenant-1'], ['ref-1', 'obpay', 'account-1', 'other']] as $args) {
            self::assertSame('not_routed', $router->route(...$args));
        }
        self::assertSame(0, $calls);
        self::assertSame(0, $verifications);
    }

    public function test_unverified_nonpaid_or_mismatched_provider_facts_never_dispatch(): void
    {
        $changes = [
            ['status' => 'pending'], ['status' => 'failed'], ['status' => 'success'], ['status' => true],
            ['reference' => 'other'], ['provider' => 'other'], ['account' => 'other'], ['transaction_id' => ''],
            ['amount' => 5001], ['amount' => '5000'], ['amount' => 5000.0], ['currency' => 'USD'], ['currency' => 'ugx'],
        ];
        $payments = [null, false, [], ...array_map(fn ($c) => array_replace($this->payment(), $c), $changes)];
        foreach ($payments as $payment) {
            $calls = 0;
            $router = new CollectionPurposeRouter(fn () => [$this->mapping()], fn () => $payment,
                ['voucher' => function () use (&$calls) {
                    $calls++;
                }, 'subscription' => function () use (&$calls) {
                    $calls++;
                }]);
            self::assertSame('not_routed', $router->route('ref-1', 'obpay', 'account-1', 'tenant-1'), json_encode($payment));
            self::assertSame(0, $calls);
        }
    }

    public function test_untrusted_or_invalid_context_is_rejected_before_dependencies(): void
    {
        $calls = 0;
        $router = new CollectionPurposeRouter(function () use (&$calls) {
            $calls++;

            return [];
        },
            function () use (&$calls) {
                $calls++;

                return null;
            });
        foreach ([['', 'obpay', 'account-1', 'tenant-1'], ['ref-1', null, 'account-1', 'tenant-1'],
            ['ref-1', 'obpay', ' account-1', 'tenant-1'], ['ref-1', 'obpay', 'account-1', 1],
            ['ref-1', 'obpay', 'account-1', "tenant\0x"]] as $args) {
            self::assertSame('not_routed', $router->route(...$args));
        }
        self::assertSame(0, $calls);
    }

    public function test_resolver_and_verifier_failures_propagate_without_dispatch(): void
    {
        foreach (['resolve', 'verify'] as $failure) {
            $calls = 0;
            $router = new CollectionPurposeRouter(
                function () use ($failure) {
                    if ($failure === 'resolve') {
                        throw new RuntimeException('unavailable');
                    }

                    return [$this->mapping()];
                },
                function () use ($failure) {
                    if ($failure === 'verify') {
                        throw new RuntimeException('unavailable');
                    }

                    return $this->payment();
                },
                ['voucher' => function () use (&$calls) {
                    $calls++;
                }]);
            try {
                $router->route('ref-1', 'obpay', 'account-1', 'tenant-1');
                self::fail('Dependency failure must propagate.');
            } catch (RuntimeException $e) {
                self::assertSame('unavailable', $e->getMessage());
                self::assertSame(0, $calls);
            }
        }
    }

    public function test_handler_failure_propagates_and_router_does_not_claim_exactly_once(): void
    {
        $calls = 0;
        $router = new CollectionPurposeRouter(fn () => [$this->mapping()], fn () => $this->payment(),
            ['voucher' => function () use (&$calls) {
                $calls++;
                throw new RuntimeException('handler failed');
            }]);
        for ($i = 0; $i < 2; $i++) {
            try {
                $router->route('ref-1', 'obpay', 'account-1', 'tenant-1');
                self::fail('Handler failure must propagate.');
            } catch (RuntimeException $e) {
                self::assertSame('handler failed', $e->getMessage());
            }
        }
        self::assertSame(2, $calls, 'Handlers must implement their own durable idempotency.');
    }

    public function test_rejects_unknown_handler_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionPurposeRouter(fn () => [], fn () => null, ['fallback' => fn () => null]);
    }
}
