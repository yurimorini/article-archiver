<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

use Yumo\LogRead\ArticleExtractor\PlainText;

/**
 * This class holds article HTML that has been purified, plus the metadata that belongs with it.
 *
 * `$html` is a UTF-8 fragment. Emit it as HTML. Do not run `htmlspecialchars` on the whole fragment.
 * `$title`, `$excerpt`, and `$siteName` are plain text copied from the extracted article:
 * store `raw()`, and use `html()` when rendering them as text.
 */
final readonly class SafeDocument
{
    /**
     * This constructor stores the purified fragment and its metadata.
     */
    public function __construct(
        /** This value holds the purified article HTML. It may be empty when every element was stripped. */
        public string $html,
        /** This value holds the article title copied from the extracted document. */
        public PlainText $title,
        /** This value holds the excerpt copied from the extracted document, or null when there was none. */
        public ?PlainText $excerpt,
        /** This value holds the site name copied from the extracted document, or null when there was none. */
        public ?PlainText $siteName,
        /** This value holds the page URL the article was fetched from. */
        public string $sourceUrl,
    ) {
    }
}
