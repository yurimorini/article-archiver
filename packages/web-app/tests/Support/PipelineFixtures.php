<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\ArtcleReader\ArticleExtractor\ExtractResult;
use Yumo\ArtcleReader\ArticleExtractor\PlainText;
use Yumo\ArtcleReader\HtmlSanitizer\SafeDocument;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;
use Yumo\ArtcleReader\Orchestrator\PipelineWarning;

/**
 * Builds library fetch outcomes for tests without a real fetch.
 */
final class PipelineFixtures
{
    /**
     * Builds a successful fetch outcome from plain strings.
     *
     * @param list<PipelineWarning> $warnings
     */
    public static function success(
        string $title = 'Title',
        ?string $excerpt = null,
        ?string $siteName = null,
        string $html = '<p>Body</p>',
        array $warnings = [],
        string $sourceUrl = 'https://example.com/a',
    ): PipelineSuccess {
        return new PipelineSuccess(
            new SafeDocument(
                $html,
                PlainText::fromUntrusted($title),
                PlainText::fromUntrustedNullable($excerpt),
                PlainText::fromUntrustedNullable($siteName),
                $sourceUrl,
            ),
            $warnings,
        );
    }

    /**
     * Builds the outcome of a page where the extractor found no article.
     */
    public static function noArticle(string $sourceUrl = 'https://example.com/a'): PipelineNoContent
    {
        return PipelineNoContent::fromExtract(ExtractResult::noContent($sourceUrl));
    }

    /**
     * Builds the outcome of a page whose article is empty after purification.
     */
    public static function noContent(string $sourceUrl = 'https://example.com/a'): PipelineNoContent
    {
        return PipelineNoContent::fromSanitizedEmpty(
            new SafeDocument('', PlainText::fromUntrusted(''), null, null, $sourceUrl),
        );
    }
}
