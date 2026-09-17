<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;

/**
 * This interface converts raw HTML bytes from a named encoding into UTF-8.
 *
 * Tests inject a fake to force conversion failures without depending on mbstring’s substitution behaviour.
 */
interface Utf8Converter
{
    /**
     * This method converts `$bytes` from `$fromEncoding` into UTF-8.
     *
     * @throws UnsupportedEncodingException When `$fromEncoding` is not available on this platform
     * @throws \Throwable When conversion fails for any other reason
     */
    public function convert(string $bytes, string $fromEncoding): string;
}
