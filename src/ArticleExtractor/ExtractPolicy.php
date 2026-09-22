<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds the Readability knobs for one `ArticleExtractor`.
 *
 * Limits are checked here so a negative cap cannot reach the vendor.
 * `charThreshold` is the vendor’s minimum article length before it retries
 * with softer flags; it does not truncate the article. `maxElemsToParse`
 * stops parsing when the DOM is larger than this many elements. Zero means
 * no element cap.
 */
final readonly class ExtractPolicy
{
    /**
     * This constructor stores the knobs and rejects negative limits.
     *
     * @throws \InvalidArgumentException When `$charThreshold` or `$maxElemsToParse` is negative
     */
    public function __construct(
        /** This value enables Readability’s `error_log` debug output, and it is also the switch that allows a PSR-3 logger to be passed in. */
        public bool $debug = false,
        /** This value rewrites relative URLs against `Utf8Html::$sourceUrl` when true. */
        public bool $fixRelativeURLs = true,
        /** This value is the minimum article text length Readability wants before it stops retrying. */
        public int $charThreshold = 500,
        /** This value is the maximum number of DOM elements to parse. Zero disables the cap. */
        public int $maxElemsToParse = 30000,
    ) {
        if ($charThreshold < 0) {
            throw new \InvalidArgumentException('charThreshold must not be negative');
        }
        if ($maxElemsToParse < 0) {
            throw new \InvalidArgumentException('maxElemsToParse must not be negative');
        }
    }
}
