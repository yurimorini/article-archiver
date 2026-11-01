<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Decides whether an import child is a broken probe step.
 */
final class ImportResult
{
    /**
     * Returns one sentence when the exit is missing or stdout has no written summary.
     *
     * Exit 0 and exit 1 are recorded outcomes when stdout contains `Written`.
     */
    public static function error(?int $exitCode, string $stdout): ?string
    {
        if (($exitCode === 0 || $exitCode === 1) && str_contains($stdout, 'Written')) {
            return null;
        }

        if ($exitCode === null) {
            return 'The import process did not exit.';
        }

        return 'The import process exited without a written summary.';
    }
}
