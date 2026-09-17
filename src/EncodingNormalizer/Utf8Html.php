<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This class wraps UTF-8 HTML from one fetched page together with the request URL and the source encoding name.
 *
 * Callers pass the HTML document body, the URI that was requested, and the encoding that was used or assumed before UTF-8 conversion.
 */
final readonly class Utf8Html
{
    public function __construct(
        /** This value holds the HTML document body that later parsing will read. */
        public string $html,
        /** This value holds the request URI this HTML was fetched from, and callers use it as the base for relative URLs. */
        public string $sourceUrl,
        /** This value holds the encoding name that was used or assumed before conversion to UTF-8. */
        public string $sourceEncoding,
    ) {
    }
}
