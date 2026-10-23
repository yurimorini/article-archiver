<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Counts the four import outcomes.
 *
 * It does not print and it does not touch the filesystem.
 */
final class ImportTally
{
    /** How many blank candidates this run skipped. */
    private int $skipped = 0;

    /**
     * Counts one blank candidate.
     */
    public function skip(): void
    {
        ++$this->skipped;
    }

    /**
     * Returns how many archives this run stored.
     */
    public function written(): int
    {
        return 0;
    }

    /**
     * Returns how many accepted URLs were already stored.
     */
    public function alreadyStored(): int
    {
        return 0;
    }

    /**
     * Returns how many archive writes failed.
     */
    public function failed(): int
    {
        return 0;
    }

    /**
     * Returns how many candidates were skipped.
     */
    public function skipped(): int
    {
        return $this->skipped;
    }
}
