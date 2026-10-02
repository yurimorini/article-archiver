<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Result of a successful `UrlGuard::guard()` call: the trusted destination and the original input.
 *
 * Use `$safe` as the destination for an HTTP request. Keep `$original` for logs and error
 * messages; it must not be used as the request URL.
 */
final class GuardResult
{
    /**
     * Stores the trusted destination next to the string the caller originally provided.
     */
    public function __construct(
        /** Destination that passed parsing, local policy, and DNS/SSRF (server-side request forgery) checks. */
        public readonly SafeFetchTarget $safe,
        /** Input string as received, including any surrounding whitespace; for logs only. */
        public readonly string $original,
    ) {
    }
}
