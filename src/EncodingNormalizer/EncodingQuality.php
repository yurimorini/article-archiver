<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This enum indicates whether converting the response bytes into UTF-8 HTML was clean or best-effort.
 *
 * The Ok case means a trusted encoding was found and conversion succeeded cleanly.
 * The Degraded case still yields UTF-8 HTML, but conversion was lossy or the encoding was only guessed.
 */
enum EncodingQuality
{
    case Ok;
    case Degraded;
}
