<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Thrown when `UrlGuard` refuses a URL.
 *
 * Inspect `$error` to tell syntax, policy, and DNS/SSRF (server-side request forgery) failures apart. 
 */
final class UrlGuardException extends \RuntimeException
{
    public function __construct(
        /** Why the URL was refused: syntax, local policy, or DNS/SSRF rejection. */
        public readonly UrlGuardError $error,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $error->defaultMessage(),
            0,
            $previous,
        );
    }
}
