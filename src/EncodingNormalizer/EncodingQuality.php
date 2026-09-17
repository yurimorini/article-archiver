<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * How callers should treat an `EncodingOutcome`.
 *
 * `Ok` means a trusted encoding was found and conversion succeeded cleanly.
 * `Degraded` still carries UTF-8 HTML, but conversion was lossy or the encoding was only guessed.
 */
enum EncodingQuality
{
    case Ok;
    case Degraded;
}
