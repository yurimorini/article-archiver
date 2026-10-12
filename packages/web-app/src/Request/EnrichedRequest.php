<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Request;

/**
 * Facts read from one API request before the action runs.
 */
final readonly class EnrichedRequest
{
    public function __construct(
        public string $requestId,
        public ?string $userAgent,
        public ?string $referer,
    ) {
    }
}
