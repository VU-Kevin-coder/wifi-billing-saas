<?php

namespace App\Services\Policy;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Isolate security-policy reads without masking transaction integrity failures. */
class PolicyRead
{
    /** @return array{0: bool, 1: mixed} Success and result, or failure and null. */
    public static function run(Closure $read): array
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        if ($level > 0 && (! $connection->getQueryGrammar()->supportsSavepoints() || ! $connection->getPdo()->inTransaction())) {
            throw new RuntimeException('Cannot safely isolate policy read in the current transaction.');
        }

        $readError = null;
        $activeAtFailure = false;
        try {
            $result = $connection->transaction(function () use ($connection, $read, &$readError, &$activeAtFailure) {
                try {
                    return $read();
                } catch (QueryException $e) {
                    $readError = $e;
                    $activeAtFailure = $connection->getPdo()->inTransaction();
                    throw $e;
                }
            });

            return [true, $result];
        } catch (QueryException $e) {
            // Only the original read error is eligible. Begin/commit/rollback errors,
            // Laravel's nested DeadlockException, and other Throwables must escape.
            if ($e !== $readError || ! $activeAtFailure || ! self::missingTable($connection->getDriverName(), $e)
                || $connection->transactionLevel() !== $level
                || $connection->getPdo()->inTransaction() !== ($level > 0)) {
                throw $e;
            }
            report($e);

            return [false, null];
        }
    }

    private static function missingTable(string $driver, QueryException $e): bool
    {
        $state = $e->errorInfo[0] ?? null;
        $native = $e->errorInfo[1] ?? null;

        return match ($driver) {
            'pgsql' => $state === '42P01',
            'mysql' => $state === '42S02' && (int) $native === 1146,
            'sqlite' => $state === 'HY000' && (int) $native === 1
                && str_contains($e->getPrevious()?->getMessage() ?? '', 'no such table:'),
            default => false,
        };
    }
}
