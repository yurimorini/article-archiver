<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Formats one result row. The label occupies 16 characters and the value starts at column 17.
 */
final class ResultLineFormatter
{
    /**
     * Returns the row for a blank candidate.
     */
    public function blank(): string
    {
        return str_pad('SKIPPED', 16) . '--';
    }

    /**
     * Returns the row for `$result`.
     */
    public function line(ImportResult $result): string
    {
        $label = match ($result->outcome) {
            ImportOutcome::Archived => 'ARCHIVED',
            ImportOutcome::AlreadyStored => 'ALREADY STORED',
            ImportOutcome::Failed => 'ERROR',
            ImportOutcome::Skipped => 'SKIPPED',
        };

        return str_pad($label, 16) . ($result->url ?? '');
    }
}
