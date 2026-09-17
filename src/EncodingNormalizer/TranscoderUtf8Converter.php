<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Transcoder;
use Ddeboer\Transcoder\TranscoderInterface;

/**
 * Converts encodings with `Ddeboer\Transcoder\Transcoder` (mbstring, then iconv).
 *
 * Lossy conversion uses `mb_convert_encoding` when `$fromEncoding` is a known mbstring
 * encoding, and `mb_scrub` otherwise, so invalid sequences become replacement characters
 * instead of aborting.
 */
final class TranscoderUtf8Converter implements Utf8Converter
{
    /** Vendor transcoder that maps `$fromEncoding` to UTF-8. */
    private TranscoderInterface $transcoder;

    /**
     * Creates the adapter. When `$transcoder` is omitted, `Transcoder::create()` is used.
     */
    public function __construct(?TranscoderInterface $transcoder = null)
    {
        $this->transcoder = $transcoder ?? Transcoder::create();
    }

    /**
     * Converts `$bytes` from `$fromEncoding` into UTF-8.
     *
     * @throws \Ddeboer\Transcoder\Exception\UnsupportedEncodingException When `$fromEncoding` is not available on this platform
     * @throws \Throwable When conversion fails for any other reason
     */
    public function convert(string $bytes, string $fromEncoding): string
    {
        return $this->transcoder->transcode($bytes, $fromEncoding, 'UTF-8');
    }

    public function convertLossy(string $bytes, string $fromEncoding): string
    {
        if ($this->isSupportedMbEncoding($fromEncoding) && !$this->isUtf8Name($fromEncoding)) {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $fromEncoding);
            if (!is_string($converted)) {
                throw new \RuntimeException('mb_convert_encoding did not return a string');
            }

            return mb_scrub($converted, 'UTF-8');
        }

        return mb_scrub($bytes, 'UTF-8');
    }

    private function isUtf8Name(string $encoding): bool
    {
        return strtoupper(str_replace(['-', '_'], '', $encoding)) === 'UTF8';
    }

    private function isSupportedMbEncoding(string $encoding): bool
    {
        foreach (mb_list_encodings() as $name) {
            if (strcasecmp($name, $encoding) === 0) {
                return true;
            }
        }

        return false;
    }
}
