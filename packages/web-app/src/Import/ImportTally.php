<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Counts the four import outcomes and keeps error rows in file order.
 *
 * It does not print and it does not touch the filesystem.
 */
final class ImportTally
{
    /** How many archives this run stored. */
    private int $written = 0;

    /** How many accepted URLs were already stored. */
    private int $alreadyStored = 0;

    /** How many archive writes failed. */
    private int $failed = 0;

    /** How many candidates were skipped. */
    private int $skipped = 0;

    /** @var list<array{url: string, code: string}> */
    private array $errors = [];

    /**
     * Counts one blank candidate.
     */
    public function skip(): void
    {
        ++$this->skipped;
    }

    /**
     * Counts `$result` and, when it failed, keeps the URL and the code.
     */
    public function record(ImportResult $result): void
    {
        match ($result->outcome) {
            ImportOutcome::Archived => ++$this->written,
            ImportOutcome::AlreadyStored => ++$this->alreadyStored,
            ImportOutcome::Skipped => ++$this->skipped,
            ImportOutcome::Failed => $this->recordFailure($result),
        };
    }

    /**
     * Returns how many archives this run stored.
     */
    public function written(): int
    {
        return $this->written;
    }

    /**
     * Returns how many accepted URLs were already stored.
     */
    public function alreadyStored(): int
    {
        return $this->alreadyStored;
    }

    /**
     * Returns how many archive writes failed.
     */
    public function failed(): int
    {
        return $this->failed;
    }

    /**
     * Returns how many candidates were skipped.
     */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * Returns the failed URLs and codes in file order.
     *
     * @return list<array{url: string, code: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function recordFailure(ImportResult $result): void
    {
        ++$this->failed;
        $this->errors[] = [
            'url' => $result->url ?? '',
            'code' => $result->code ?? '',
        ];
    }
}
