<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Kind of failure reported by `UrlGuardException`.
 *
 * In every case the URL must not be fetched. The enum only tells callers *why* it was refused,
 * so logs or exit codes can differ without a hierarchy of exception classes.
 * SSRF means server-side request forgery: tricking this process into requesting an internal address.
 */
enum UrlGuardError
{
    /** The string could not be parsed as a usable URL, or the host is missing. No network I/O is involved. */
    case Syntax;

    /** The string parsed, but local rules reject it (scheme, credentials, literal IP host, or non-ASCII hostname). */
    case Policy;

    /** Hostname lookup failed, the resolved addresses are not public, or the SSRF checker refused the URL. */
    case Rejected;

    /**
     * Returns the standard message for this kind of failure when the throw site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Syntax => 'URL is missing, malformed, or has no host',
            self::Policy => 'URL scheme or credentials are not allowed',
            self::Rejected => 'URL was rejected by SSRF/DNS validation',
        };
    }
}
