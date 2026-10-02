<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractResult;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;
use Yumo\LogRead\Orchestrator\PipelineEmptyReason;
use Yumo\LogRead\Orchestrator\PipelineNoContent;
use Yumo\LogRead\Orchestrator\PipelineWarning;
use Yumo\LogRead\Orchestrator\PipelineWarningCode;

final class PipelineNoContentTest extends TestCase
{
    public function test_from_extract_copies_metadata_and_warnings(): void
    {
        $title = PlainText::fromUntrusted('OG Title');
        $excerpt = PlainText::fromUntrusted('Just a blurb');
        $siteName = PlainText::fromUntrusted('Example News');
        $warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, 'guessed');
        $extract = ExtractResult::noContent('https://example.com/article', $title, $excerpt, $siteName);

        $result = PipelineNoContent::fromExtract($extract, [$warning]);

        self::assertSame(PipelineEmptyReason::ExtractNoContent, $result->reason);
        self::assertSame('https://example.com/article', $result->sourceUrl);
        self::assertSame($title, $result->title);
        self::assertSame($excerpt, $result->excerpt);
        self::assertSame($siteName, $result->siteName);
        self::assertSame([$warning], $result->warnings);
    }

    public function test_from_extract_uses_empty_title_when_metadata_is_missing(): void
    {
        $result = PipelineNoContent::fromExtract(ExtractResult::noContent('https://example.com/empty'));

        self::assertSame(PipelineEmptyReason::ExtractNoContent, $result->reason);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame([], $result->warnings);
    }

    public function test_from_extract_rejects_an_article_result(): void
    {
        $extract = ExtractResult::ok(new ReadableDocument(
            PlainText::fromUntrusted('Title'),
            null,
            null,
            '<p>Hello</p>',
            'https://example.com/article',
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Extract result must be NoContent');

        PipelineNoContent::fromExtract($extract);
    }

    public function test_from_sanitized_empty_copies_metadata_and_warnings(): void
    {
        $title = PlainText::fromUntrusted('Title');
        $excerpt = PlainText::fromUntrusted('Excerpt');
        $siteName = PlainText::fromUntrusted('News');
        $warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, 'guessed');
        $document = new SafeDocument('   ', $title, $excerpt, $siteName, 'https://example.com/article');

        $result = PipelineNoContent::fromSanitizedEmpty($document, [$warning]);

        self::assertSame(PipelineEmptyReason::SanitizedEmpty, $result->reason);
        self::assertSame('https://example.com/article', $result->sourceUrl);
        self::assertSame($title, $result->title);
        self::assertSame($excerpt, $result->excerpt);
        self::assertSame($siteName, $result->siteName);
        self::assertSame([$warning], $result->warnings);
    }

    public function test_from_sanitized_empty_rejects_html_that_still_has_text(): void
    {
        $document = new SafeDocument(
            '<p>Hello</p>',
            PlainText::fromUntrusted('Title'),
            null,
            null,
            'https://example.com/article',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sanitized article HTML must be empty');

        PipelineNoContent::fromSanitizedEmpty($document);
    }
}
