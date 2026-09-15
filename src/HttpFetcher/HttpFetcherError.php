<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Kind of failure reported by `HttpFetcherException`.
 *
 * In every case the fetch must be treated as failed; the enum only tells callers
 * *why*, so logs or exit codes can differ without a hierarchy of exception classes.
 */
enum HttpFetcherError
{
    /** The transfer itself failed: timeout, connection refused, or another network error. */
    case Transport;

    /** The response status was not `2xx` and not a redirect (for example `404` or `500`). */
    case HttpStatus;

    /** The response status was `3xx`. Redirects are disabled; the `Location` is not fetched. */
    case Redirect;

    /** The `Content-Type` header was missing or was not an allowed HTML type. */
    case ContentType;

    /** The response body exceeded the configured size cap, by header or while streaming. */
    case BodyTooLarge;

    /**
     * Returns the standard message for this kind of failure when the throw site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Transport => 'HTTP transport failed (timeout, connect, or network error)',
            self::HttpStatus => 'HTTP response status is not successful',
            self::Redirect => 'HTTP redirect is not followed',
            self::ContentType => 'Response Content-Type is not an allowed HTML type',
            self::BodyTooLarge => 'Response body exceeds the configured size limit',
        };
    }
}
