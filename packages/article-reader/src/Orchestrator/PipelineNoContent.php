<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

use Yumo\LogRead\ArticleExtractor\ExtractResult;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;

/**
 * This class is a finished fetch that has no article body to store.
 *
 * Metadata and any soft warnings are kept so the caller can log why the body is missing.
 * Build one with `fromExtract()` or `fromSanitizedEmpty()` rather than `new`.
 */
final readonly class PipelineNoContent
{
    /**
     * This constructor stores an empty outcome. Call the static factories instead of this constructor.
     *
     * @param list<PipelineWarning> $warnings
     */
    private function __construct(
        /** This value explains why there is no article body. */
        public PipelineEmptyReason $reason,
        /** This value holds the page URL the fetch used. */
        public string $sourceUrl,
        /** This value holds the title found on the page, or empty plain text when none was found. */
        public PlainText $title,
        /** This value holds the excerpt, or null when none was found. */
        public ?PlainText $excerpt,
        /** This value holds the site name, or null when none was found. */
        public ?PlainText $siteName,
        /** This value holds soft problems that occurred before the empty outcome, such as a lossy encoding conversion. */
        public array $warnings = [],
    ) {
    }

    /**
     * This method builds an empty outcome from an extraction that found no article.
     *
     * @param list<PipelineWarning> $warnings
     *
     * @throws \InvalidArgumentException When `$result` still contains an article
     */
    public static function fromExtract(ExtractResult $result, array $warnings = []): self
    {
        if (!$result->isNoContent()) {
            throw new \InvalidArgumentException('Extract result must be NoContent');
        }

        return new self(
            PipelineEmptyReason::ExtractNoContent,
            $result->sourceUrl,
            $result->title ?? PlainText::fromUntrusted(''),
            $result->excerpt,
            $result->siteName,
            $warnings,
        );
    }

    /**
     * This method builds an empty outcome from a purified document whose HTML is blank.
     *
     * @param list<PipelineWarning> $warnings
     *
     * @throws \InvalidArgumentException When `$document` still contains non-whitespace HTML
     */
    public static function fromSanitizedEmpty(SafeDocument $document, array $warnings = []): self
    {
        if (trim($document->html) !== '') {
            throw new \InvalidArgumentException('Sanitized article HTML must be empty');
        }

        return new self(
            PipelineEmptyReason::SanitizedEmpty,
            $document->sourceUrl,
            $document->title,
            $document->excerpt,
            $document->siteName,
            $warnings,
        );
    }
}
