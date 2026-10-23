<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The result of one candidate: what to print, and the failure code when the write failed.
 */
#[Exclude]
final readonly class ImportResult
{
    /**
     * Creates the result of one candidate.
     */
    public function __construct(
        /** Which of the four labels this row prints. */
        public ImportOutcome $outcome,
        /** The trimmed candidate, or null when the row was blank. */
        public ?string $url,
        /** The archive failure code, or null when the row is not an error. */
        public ?string $code = null,
    ) {
    }
}
