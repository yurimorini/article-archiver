<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Transcoder;
use Ddeboer\Transcoder\TranscoderInterface;

/**
 * This class converts encodings with `Ddeboer\Transcoder\Transcoder` (mbstring, then iconv).
 */
final class TranscoderUtf8Converter implements Utf8Converter
{
    /** This value holds the vendor transcoder that maps `$fromEncoding` to UTF-8. */
    private TranscoderInterface $transcoder;

    /**
     * This constructor creates the adapter. When `$transcoder` is omitted, `Transcoder::create()` is used.
     */
    public function __construct(?TranscoderInterface $transcoder = null)
    {
        $this->transcoder = $transcoder ?? Transcoder::create();
    }

    /**
     * This method converts `$bytes` from `$fromEncoding` into UTF-8.
     */
    public function convert(string $bytes, string $fromEncoding): string
    {
        return $this->transcoder->transcode($bytes, $fromEncoding, 'UTF-8');
    }
}
