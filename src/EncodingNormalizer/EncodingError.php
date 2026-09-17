<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Kind of encoding problem when document bytes cannot be interpreted or converted to UTF-8 as required.
 *
 * Callers attach a case to a warning or to a hard failure so logs and handling can differ
 * without a separate exception class for each situation.
 */
enum EncodingError
{
    /** No BOM, HTTP charset, or meta declaration, and UTF-8 validity / guess was weak or failed. */
    case Undeclared;

    /** The declared encoding name is unknown on this platform. */
    case Unsupported;

    /** Strict conversion to UTF-8 failed or the source bytes were not valid in the declared encoding. */
    case Conversion;

    /**
     * Returns the standard message for this kind of problem when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Undeclared => 'Character encoding could not be determined',
            self::Unsupported => 'Character encoding is not supported for conversion',
            self::Conversion => 'Character encoding conversion to UTF-8 failed',
        };
    }
}
