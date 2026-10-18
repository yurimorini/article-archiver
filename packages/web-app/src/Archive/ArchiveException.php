<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that a page could not be archived.
 *
 * The original failure, when there is one, is available through `getPrevious()`.
 */
#[Exclude]
final class ArchiveException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
