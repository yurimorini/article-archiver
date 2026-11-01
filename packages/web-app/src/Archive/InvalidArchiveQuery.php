<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that an archive page query string is not a legal value.
 *
 * This exception carries no HTTP status and no client sentence. The translator owns both.
 */
#[Exclude]
final class InvalidArchiveQuery extends \RuntimeException
{
}
