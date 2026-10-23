<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The four results one import row can print.
 */
#[Exclude]
enum ImportOutcome
{
    /** The candidate was blank or the URL guard rejected it. */
    case Skipped;

    /** This run stored a new archive. */
    case Archived;

    /** The URL was already stored, so nothing was fetched. */
    case AlreadyStored;

    /** The archive write failed after the guard accepted the URL. */
    case Failed;
}
