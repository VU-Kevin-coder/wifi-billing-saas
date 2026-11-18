<?php

namespace App\Services;

use InvalidArgumentException;
use OverflowException;

/**
 * Detached arithmetic only: this class does not authenticate or order events,
 * find generations, take locks, persist data, or release device capacity.
 * Callers must establish all of those preconditions before using its result.
 */
final class RadiusGenerationCounterDelta
{
    private const WORD_MAX = 4294967295;

    private const WORD_BASE = 4294967296;

    /**
     * Reconcile one proven generation against locked aggregate totals.
     * Each direction is either a complete (octets, gigawords) pair or entirely
     * absent. No inference of wrap, reset, zero, or missing counters is made.
     *
     * @return array{generation_input: int, generation_output: int, aggregate_input: int, aggregate_output: int, delta_input: int, delta_output: int}
     *
     * @throws InvalidArgumentException|OverflowException
     */
    public static function calculate(
        mixed $aggregateInput,
        mixed $aggregateOutput,
        mixed $generationInput,
        mixed $generationOutput,
        mixed $inputOctets,
        mixed $inputGigawords,
        mixed $outputOctets,
        mixed $outputGigawords,
    ): array {
        if (PHP_INT_SIZE < 8) {
            throw new OverflowException('64-bit integers are required.');
        }

        foreach ([$aggregateInput, $aggregateOutput, $generationInput, $generationOutput] as $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Stored counters must be non-negative integers.');
            }
        }
        if ($generationInput > $aggregateInput || $generationOutput > $aggregateOutput) {
            throw new InvalidArgumentException('Aggregate cannot be smaller than its generation.');
        }

        $input = self::counter($inputOctets, $inputGigawords, $generationInput);
        $output = self::counter($outputOctets, $outputGigawords, $generationOutput);
        $inputDelta = $input - $generationInput;
        $outputDelta = $output - $generationOutput;
        if ($inputDelta > PHP_INT_MAX - $aggregateInput || $outputDelta > PHP_INT_MAX - $aggregateOutput) {
            throw new OverflowException('Aggregate counter overflow.');
        }

        return [
            'generation_input' => $input,
            'generation_output' => $output,
            'aggregate_input' => $aggregateInput + $inputDelta,
            'aggregate_output' => $aggregateOutput + $outputDelta,
            'delta_input' => $inputDelta,
            'delta_output' => $outputDelta,
        ];
    }

    private static function counter(mixed $octets, mixed $gigawords, int $previous): int
    {
        if ($octets === null && $gigawords === null) {
            return $previous;
        }
        if (! is_int($octets) || ! is_int($gigawords)
            || $octets < 0 || $octets > self::WORD_MAX
            || $gigawords < 0 || $gigawords > self::WORD_MAX) {
            throw new InvalidArgumentException('Counter requires two unsigned 32-bit integers.');
        }
        if ($gigawords > intdiv(PHP_INT_MAX - $octets, self::WORD_BASE)) {
            throw new OverflowException('Generation counter overflow.');
        }

        $value = $gigawords * self::WORD_BASE + $octets;
        if ($value < $previous) {
            throw new InvalidArgumentException('Generation counter regressed.');
        }

        return $value;
    }
}
