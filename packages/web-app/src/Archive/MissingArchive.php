<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that no archive has the requested id.
 *
 * This exception carries no HTTP status and no client sentence. The translator owns both.
 */
#[Exclude]
final class MissingArchive extends \RuntimeException
{
}
