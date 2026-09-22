<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds extracted article HTML plus the metadata that belongs with it.
 *
 * `$content` is an HTML fragment from Readability. It has not been checked for
 * scripts or unsafe URLs. `$title`, `$excerpt`, and `$siteName` are plain text:
 * store `raw()`, and use `html()` when rendering them as text.
 */
final readonly class ReadableDocument
{
    /**
     * This constructor stores the article fragment and its metadata.
     */
    public function __construct(
        /** This value holds the article title. `raw()` may be empty when Readability found none. */
        public PlainText $title,
        /** This value holds the description or short excerpt, or null when none was found. */
        public ?PlainText $excerpt,
        /** This value holds the site name, or null when none was found. */
        public ?PlainText $siteName,
        /** This value holds the article HTML fragment. It is not safe to print as trusted HTML yet. */
        public string $content,
        /** This value holds the page URL the HTML was fetched from, used as the base for relative links. */
        public string $sourceUrl,
    ) {
    }
}
