<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This enum records where the source encoding name was taken from.
 *
 * The value is a diagnostic for logs and tests. Callers must not re-run charset detection
 * from this value; they already have UTF-8 HTML on the same object.
 */
enum EncodingSource
{
    /** This case applies when a byte-order mark appears at the start of the raw body. */
    case Bom;

    /** This case applies when the encoding name came from the charset parameter on the HTTP Content-Type header. */
    case HttpHeader;

    /** This case applies when the encoding name came from an HTML meta charset or http-equiv content-type in the first 1024 bytes. */
    case Meta;

    /** This case applies when no declaration was present and the raw bytes were already valid UTF-8. */
    case Utf8Default;

    /** This case applies when mb_detect_encoding guessed the encoding among a small candidate list, which is always a weak signal. */
    case Detect;

    /** This case applies when no trusted encoding was found and bytes were repaired into UTF-8 with substitution. */
    case Lossy;
}
