<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Fixed timeouts, body size cap, and outgoing headers for one `HttpFetcher`.
 *
 * Construction rejects non-positive timeouts and size cap, and blank header values,
 * so an `HttpFetcher` never runs with a silently unusable policy.
 */
final readonly class FetchPolicy
{
    /**
     * @param int $timeoutSeconds Total request timeout, in seconds
     * @param int $connectTimeoutSeconds Connection timeout, in seconds
     * @param int $maxBytes Hard cap on the decoded response body size, in bytes
     * @param string $userAgent Outgoing `User-Agent` header value
     * @param string $accept Outgoing `Accept` header value
     * @param bool $debug When true, Guzzle transfer summaries are logged at PSR-3 `debug` level
     */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 5,
        public int $maxBytes = 5_000_000,
        public string $userAgent = 'LogRead/0.1',
        public string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        public bool $debug = false,
    ) {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be positive');
        }
        if ($connectTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be positive');
        }
        if ($maxBytes <= 0) {
            throw new \InvalidArgumentException('maxBytes must be positive');
        }
        if (trim($userAgent) === '') {
            throw new \InvalidArgumentException('userAgent must not be empty');
        }
        if (trim($accept) === '') {
            throw new \InvalidArgumentException('accept must not be empty');
        }
    }
}
