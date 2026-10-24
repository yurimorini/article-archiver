<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\HttpFetcher;

/**
 * Describes an HTTP redirect without following it or reading its response body.
 */
final readonly class RedirectResponse
{
    /**
     * @param int $statusCode HTTP redirect status code from 300 through 399
     * @param string $location First `Location` header line, or an empty string when the header is missing
     */
    public function __construct(
        public int $statusCode,
        public string $location,
    ) {
    }
}
