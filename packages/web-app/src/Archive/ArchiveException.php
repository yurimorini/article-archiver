<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reports that a page could not be archived.
 *
 * `$failure` names the reason. The original failure, when there is one, is available through `getPrevious()`.
 */
#[Exclude]
final class ArchiveException extends \RuntimeException
{
    /**
     * Creates the exception from the failure kind, the original failure that caused it, and the status the page's site sent.
     */
    public function __construct(
        /** The reason the page could not be archived. */
        public readonly ArchiveFailure $failure,
        ?\Throwable $cause = null,
        /** The HTTP status the page's site answered with, or null when there is none. */
        public readonly ?int $upstreamStatus = null,
    ) {
        parent::__construct('Archive failed: ' . $failure->value, 0, $cause);
    }
}
