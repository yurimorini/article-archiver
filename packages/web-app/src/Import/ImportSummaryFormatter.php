<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Turns a tally and a path into the import heading and the totals.
 *
 * It does not read the file, archive, or write `error.log`.
 */
final class ImportSummaryFormatter
{
    /**
     * Returns the heading for `$path`, including the blank line under it.
     */
    public function heading(string $path): string
    {
        return "Importing: {$path}\n\n";
    }

    /**
     * Returns the four totals. When `$errorLogPath` is set, a blank line and the details sentence follow them.
     */
    public function footer(ImportTally $tally, ?string $errorLogPath): string
    {
        $footer = sprintf(
            "Written %d\nAlready stored %d\nFailed %d\nSkipped %d\n",
            $tally->written(),
            $tally->alreadyStored(),
            $tally->failed(),
            $tally->skipped(),
        );
        if ($errorLogPath !== null) {
            $footer .= "\nFor error details see {$errorLogPath}\n";
        }

        return $footer;
    }
}
