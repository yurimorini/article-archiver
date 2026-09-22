<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This enum names hard failures that stop HTML purification for one article.
 *
 * A fragment that loses a script, an iframe, or a disallowed URL is not one of
 * these cases. Purification still returns a `SafeDocument`, even when the
 * fragment is empty.
 */
enum HtmlSanitizerError
{
    /** This case applies when the purifier config or the definition-cache directory cannot be used. */
    case Configuration;

    /** This case applies when the adapter catches a throwable that is not a purifier configuration failure. */
    case Unexpected;

    /**
     * This method returns the standard message for this failure when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Configuration => 'HTML sanitizer configuration or runtime setup failed',
            self::Unexpected => 'HTML sanitization failed unexpectedly',
        };
    }
}
