<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Kind of encoding problem reported as a degraded-outcome warning or on a hard-failure exception.
 *
 * Soft problems (lossy conversion, undeclared charset) use this enum on
 * `EncodingOutcome::$warning`. The exception path uses the same cases only when
 * UTF-8 HTML cannot be produced at all.
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
