<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that the directory of an owner database could not be created.
 *
 * A `\PDOException` means the file was opened and the database failed. This exception means the directory was not created, so no database call ran.
 */
#[Exclude]
final class DirectoryException extends \RuntimeException
{
}
