<?php

namespace Tests\Unit;

use App\Services\RadiusGenerationCounterDelta;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class RadiusGenerationCounterDeltaTest extends TestCase
{
    public function test_two_generations_add_only_their_own_deltas(): void
    {
        // A starts at 10/4, B starts at 20/7, then A and B update.
        $a = RadiusGenerationCounterDelta::calculate(0, 0, 0, 0, 10, 0, 4, 0);
        $b = RadiusGenerationCounterDelta::calculate($a['aggregate_input'], $a['aggregate_output'], 0, 0, 20, 0, 7, 0);
        $a = RadiusGenerationCounterDelta::calculate($b['aggregate_input'], $b['aggregate_output'], $a['generation_input'], $a['generation_output'], 13, 0, 9, 0);
        $b = RadiusGenerationCounterDelta::calculate($a['aggregate_input'], $a['aggregate_output'], $b['generation_input'], $b['generation_output'], 22, 0, 8, 0);
        self::assertSame(35, $b['aggregate_input']);
        self::assertSame(17, $b['aggregate_output']);
        self::assertSame(2, $b['delta_input']);
        self::assertSame(1, $b['delta_output']);
    }

    public function test_repeated_counters_and_missing_directions_do_not_increment_totals(): void
    {
        $result = RadiusGenerationCounterDelta::calculate(50, 60, 20, 30, 20, 0, null, null);
        self::assertSame([
            'generation_input' => 20, 'generation_output' => 30,
            'aggregate_input' => 50, 'aggregate_output' => 60,
            'delta_input' => 0, 'delta_output' => 0,
        ], $result);
    }

    public function test_gigawords_and_largest_signed_value_are_calculated_exactly(): void
    {
        $result = RadiusGenerationCounterDelta::calculate(4294967295, 0, 4294967295, 0, 2, 1, null, null);
        self::assertSame(4294967298, $result['aggregate_input']);
        self::assertSame(3, $result['delta_input']);
        $max = RadiusGenerationCounterDelta::calculate(0, 0, 0, 0, 4294967295, 2147483647, null, null);
        self::assertSame(PHP_INT_MAX, $max['aggregate_input']);
    }

    public function test_regressions_and_partially_present_counters_are_rejected(): void
    {
        foreach ([[19, 0, 30, 0], [20, 0, 29, 0], [20, null, 30, 0], [null, 0, 30, 0]] as $sample) {
            try {
                RadiusGenerationCounterDelta::calculate(50, 60, 20, 30, ...$sample);
                self::fail('Invalid event must not produce a result.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_invalid_types_ranges_and_inconsistent_stored_values_are_rejected(): void
    {
        $cases = [
            ['50', 60, 20, 30, 20, 0, 30, 0],
            [-1, 60, 0, 30, 20, 0, 30, 0],
            [19, 60, 20, 30, 20, 0, 30, 0],
            [50, 29, 20, 30, 20, 0, 30, 0],
            [50, 60, 20, 30, '20', 0, 30, 0],
            [50, 60, 20, 30, 20.0, 0, 30, 0],
            [50, 60, 20, 30, true, 0, 30, 0],
            [50, 60, 20, 30, -1, 0, 30, 0],
            [50, 60, 20, 30, 4294967296, 0, 30, 0],
            [50, 60, 20, 30, 20, 4294967296, 30, 0],
        ];
        foreach ($cases as $args) {
            try {
                RadiusGenerationCounterDelta::calculate(...$args);
                self::fail('Invalid input must not produce a result.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_overflows_in_either_generation_or_aggregate_fail_closed(): void
    {
        $cases = [
            [0, 0, 0, 0, 0, 2147483648, null, null],
            [0, 0, 0, 0, null, null, 0, 2147483648],
            [PHP_INT_MAX, 0, 0, 0, 1, 0, null, null],
            [0, PHP_INT_MAX, 0, 0, null, null, 1, 0],
        ];
        foreach ($cases as $args) {
            try {
                RadiusGenerationCounterDelta::calculate(...$args);
                self::fail('Overflow must not produce a result.');
            } catch (OverflowException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_interleaved_monotonic_updates_equal_sum_of_all_generations(): void
    {
        $input = [0, 0, 0];
        $output = [0, 0, 0];
        $totalIn = 0;
        $totalOut = 0;
        for ($step = 0; $step < 300; $step++) {
            $i = ($step * 7) % 3;
            $nextIn = $input[$i] + ($step % 11);
            $nextOut = $output[$i] + ($step % 7);
            $result = RadiusGenerationCounterDelta::calculate($totalIn, $totalOut, $input[$i], $output[$i], $nextIn, 0, $nextOut, 0);
            $input[$i] = $result['generation_input'];
            $output[$i] = $result['generation_output'];
            $totalIn = $result['aggregate_input'];
            $totalOut = $result['aggregate_output'];
            self::assertSame(array_sum($input), $totalIn);
            self::assertSame(array_sum($output), $totalOut);
        }
    }
}
