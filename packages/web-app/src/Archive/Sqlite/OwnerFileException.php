<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that an owner database file cannot be opened for this owner.
 *
 * The file has both the short name and the full hash name, or `meta.email` names a different owner.
 * This is not an `ArchiveException`, so the call answers 500 `unexpected`.
 */
#[Exclude]
final class OwnerFileException extends \RuntimeException
{
}
