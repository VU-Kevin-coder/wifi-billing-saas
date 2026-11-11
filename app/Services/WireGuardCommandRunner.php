<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class WireGuardCommandRunner
{
    public function run(array $arguments, ?string $input = null): string
    {
        $process = new Process($arguments);
        $process->setInput($input);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'WireGuard command "%s" failed with exit code %s.',
                basename($arguments[0] ?? 'unknown'),
                $process->getExitCode() ?? 'unknown',
            ));
        }

        return trim($process->getOutput());
    }
}
