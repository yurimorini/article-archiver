<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The answer of one archive command: the archive and whether this call wrote it.
 */
#[Exclude]
final readonly class ArchiveResult
{
    /**
     * Creates the answer of one archive command.
     */
    public function __construct(
        /** The stored copy. */
        public Archive $archive,
        /** Whether this call wrote the copy or found it. */
        public ArchiveOutcome $outcome,
    ) {
    }
}
