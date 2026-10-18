<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Tells whether an archive command wrote the copy or found it already stored.
 */
#[Exclude]
enum ArchiveOutcome
{
    /** This call fetched the page and stored the copy. */
    case Written;

    /** The copy was already stored, so nothing was fetched. */
    case AlreadyStored;
}
