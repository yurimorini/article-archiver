<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The order of an archive list.
 */
#[Exclude]
enum ArchiveDirection: string
{
    /** Smallest insert time first. */
    case Asc = 'asc';

    /** Largest insert time first. */
    case Desc = 'desc';
}
