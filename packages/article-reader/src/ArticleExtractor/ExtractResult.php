<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class is the outcome of extracting an article from UTF-8 HTML.
 *
 * `Ok` always carries a `ReadableDocument`. `NoContent` never does.
 * Title, excerpt, site name, and source URL are also stored on the result
 * so a no-content page can still expose the metadata Readability found.
 *
 * `$result = $extractor->extract($html);`
 */
final readonly class ExtractResult
{
    /**
     * This constructor stores one outcome and rejects an Ok without a document or a NoContent that has one.
     *
     * @throws \InvalidArgumentException When the status and `$document` disagree
     */
    private function __construct(
        /** This value selects Ok or NoContent. */
        public ExtractStatus $status,
        /** This value holds the article when status is Ok, and null when status is NoContent. */
        public ?ReadableDocument $document = null,
        /** This value holds the title. On NoContent it is empty plain text when Readability found none. */
        public ?PlainText $title = null,
        /** This value holds the excerpt, or null when none was found. */
        public ?PlainText $excerpt = null,
        /** This value holds the site name, or null when none was found. */
        public ?PlainText $siteName = null,
        /** This value holds the page URL copied from the input HTML. */
        public string $sourceUrl = '',
    ) {
        if ($status === ExtractStatus::Ok && $document === null) {
            throw new \InvalidArgumentException('Ok result requires ReadableDocument');
        }
        if ($status === ExtractStatus::NoContent && $document !== null) {
            throw new \InvalidArgumentException('NoContent must not carry ReadableDocument');
        }
    }

    /**
     * This method builds an Ok result from an article document and copies its metadata onto the result.
     */
    public static function ok(ReadableDocument $document): self
    {
        return new self(
            ExtractStatus::Ok,
            document: $document,
            title: $document->title,
            excerpt: $document->excerpt,
            siteName: $document->siteName,
            sourceUrl: $document->sourceUrl,
        );
    }

    /**
     * This method builds a NoContent result. A missing title becomes empty plain text.
     */
    public static function noContent(
        string $sourceUrl,
        ?PlainText $title = null,
        ?PlainText $excerpt = null,
        ?PlainText $siteName = null,
    ): self {
        return new self(
            ExtractStatus::NoContent,
            document: null,
            title: $title ?? PlainText::fromUntrusted(''),
            excerpt: $excerpt,
            siteName: $siteName,
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * This method returns whether an article document is present.
     */
    public function isOk(): bool
    {
        return $this->status === ExtractStatus::Ok;
    }

    /**
     * This method returns whether no article body was found.
     */
    public function isNoContent(): bool
    {
        return $this->status === ExtractStatus::NoContent;
    }
}
