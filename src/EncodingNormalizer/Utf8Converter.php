<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;

/**
 * Converts raw HTML bytes from a named encoding into UTF-8.
 *
 * Production uses `TranscoderUtf8Converter`. Tests inject a fake to force conversion
 * failures without depending on mbstring’s substitution behaviour.
 */
interface Utf8Converter
{
    /**
     * Converts `$bytes` from `$fromEncoding` into UTF-8.
     *
     * @throws UnsupportedEncodingException When `$fromEncoding` is not available on this platform
     * @throws \Throwable When conversion fails for any other reason
     */
    public function convert(string $bytes, string $fromEncoding): string;

    /**
     * Converts `$bytes` into UTF-8 with substitution or ignore so the result is valid UTF-8 when possible.
     *
     * `$fromEncoding` is used when it is a known mbstring encoding; otherwise the bytes are
     * treated as UTF-8 and invalid sequences are replaced.
     *
     * @throws \Throwable When even a lossy conversion cannot produce UTF-8
     */
    public function convertLossy(string $bytes, string $fromEncoding): string;
}
