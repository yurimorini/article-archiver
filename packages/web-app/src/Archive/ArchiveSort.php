<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The field an archive list is ordered by.
 *
 * `archived` is the only token. It names the insert time.
 */
#[Exclude]
enum ArchiveSort: string
{
    /** Order by the insert time. */
    case Archived = 'archived';
}
