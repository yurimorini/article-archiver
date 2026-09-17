<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This enum names why converting response bytes into UTF-8 HTML failed.
 *
 * Callers attach a case to a warning or to a hard failure so that logs and handling can differ
 * without a separate exception class for each situation.
 */
enum EncodingError
{
    /** This case applies when no BOM, HTTP charset, or meta declaration is present and UTF-8 validity or guessing was weak or failed. */
    case Undeclared;

    /** This case applies when the declared encoding name is unknown on this platform. */
    case Unsupported;

    /** This case applies when strict conversion to UTF-8 failed or the source bytes were not valid in the declared encoding. */
    case Conversion;

    /**
     * This method returns the standard message for this kind of problem when the call site does not provide a more specific one.
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
