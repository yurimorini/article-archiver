<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * UTF-8 HTML taken from one fetched page, plus the URL that page was requested as.
 *
 * `$html` is the document body to parse. `$sourceUrl` is copied from the fetch request
 * URI so later steps can resolve relative links against the real page address.
 */
final readonly class Utf8Html
{
    public function __construct(
        /** HTML bytes that are valid UTF-8 and do not start with a UTF-8 BOM. */
        public string $html,
        /** Request URI this HTML was fetched from; used as the base for relative URLs. */
        public string $sourceUrl,
        /** Encoding name that was used or assumed before conversion to UTF-8. */
        public string $sourceEncoding,
    ) {
    }
}
