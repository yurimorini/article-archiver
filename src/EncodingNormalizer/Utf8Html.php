<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This class holds UTF-8 HTML from one fetched page together with the request URL, source encoding name, and {@see EncodingSource} provenance.
 *
 * The HTML bytes are valid UTF-8 and do not start with a UTF-8 BOM. Degraded conversion may
 * still contain replacement characters or mojibake, but the bytes are UTF-8.
 * The source URL is copied from the fetch request URI so later steps can resolve relative
 * links against the real page address.
 *
 * Production code constructs this type from `EncodingNormalizer`. Tests may construct
 * one directly when the UTF-8 invariants already hold.
 */
final readonly class Utf8Html
{
    /**
     * This constructor stores UTF-8 HTML and provenance after validating encoding, BOM, URL, and encoding name constraints.
     *
     * @throws \InvalidArgumentException When `$html` is not valid UTF-8, starts with a UTF-8 BOM, or trimmed `$sourceUrl` or `$sourceEncoding` is empty
     */
    public function __construct(
        /** This value holds HTML that is valid UTF-8 and does not start with a UTF-8 BOM. */
        public string $html,
        /** This value holds the request URI this HTML was fetched from, and callers use it as the base for relative URLs. */
        public string $sourceUrl,
        /** This value holds the encoding name that was used or assumed before conversion to UTF-8. */
        public string $sourceEncoding,
        /** This value records where `$sourceEncoding` was taken from. */
        public EncodingSource $source,
    ) {
        if (!mb_check_encoding($html, 'UTF-8')) {
            throw new \InvalidArgumentException('html must be valid UTF-8');
        }
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            throw new \InvalidArgumentException('html must not start with a UTF-8 BOM');
        }
        if (trim($sourceUrl) === '') {
            throw new \InvalidArgumentException('sourceUrl must not be empty');
        }
        if (trim($sourceEncoding) === '') {
            throw new \InvalidArgumentException('sourceEncoding must not be empty');
        }
    }
}
