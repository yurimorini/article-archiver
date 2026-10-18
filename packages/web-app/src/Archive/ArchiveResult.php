<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The answer of one archive command: the light fields and whether this call wrote them.
 */
#[Exclude]
final readonly class ArchiveResult
{
    /**
     * Creates the answer of one archive command.
     */
    public function __construct(
        /** The light fields of the stored copy. */
        public ArchiveSummary $summary,
        /** Whether this call wrote the copy or found it. */
        public ArchiveOutcome $outcome,
    ) {
    }
}
