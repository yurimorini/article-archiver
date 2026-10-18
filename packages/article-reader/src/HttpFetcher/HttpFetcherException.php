<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\HttpFetcher;

/**
 * Thrown when `HttpFetcher` cannot produce a `FetchedPage`.
 *
 * Inspect `$error` to tell transport, status, redirect, Content-Type, and body-size failures apart.
 */
final class HttpFetcherException extends \RuntimeException
{
    public function __construct(
        /** Why the fetch failed. */
        public readonly HttpFetcherError $error,
        string $message = '',
        ?\Throwable $previous = null,
        /** HTTP status number the site answered with. Set for `HttpStatus` and `Redirect`, null otherwise. */
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $error->defaultMessage(),
            0,
            $previous,
        );
    }
}
