<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractResult;
use Yumo\LogRead\ArticleExtractor\ExtractStatus;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

final class ExtractResultTest extends TestCase
{
    public function test_ok_factory_mirrors_the_document(): void
    {
        $document = $this->document();
        $result = ExtractResult::ok($document);

        self::assertSame(ExtractStatus::Ok, $result->status);
        self::assertTrue($result->isOk());
        self::assertFalse($result->isNoContent());
        self::assertSame($document, $result->document);
        self::assertSame($document->title, $result->title);
        self::assertSame($document->excerpt, $result->excerpt);
        self::assertSame($document->siteName, $result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    public function test_no_content_defaults_title_to_empty_plain_text(): void
    {
        $result = ExtractResult::noContent('https://ex.com/a');

        self::assertSame(ExtractStatus::NoContent, $result->status);
        self::assertTrue($result->isNoContent());
        self::assertFalse($result->isOk());
        self::assertNull($result->document);
        self::assertInstanceOf(PlainText::class, $result->title);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    public function test_no_content_keeps_metadata_when_provided(): void
    {
        $title = PlainText::fromUntrusted('OG Title');
        $excerpt = PlainText::fromUntrusted('Just a blurb');
        $siteName = PlainText::fromUntrusted('Example News');
        $result = ExtractResult::noContent('https://ex.com/a', $title, $excerpt, $siteName);

        self::assertNull($result->document);
        self::assertSame($title, $result->title);
        self::assertSame($excerpt, $result->excerpt);
        self::assertSame($siteName, $result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    private function document(): ReadableDocument
    {
        return new ReadableDocument(
            title: PlainText::fromUntrusted('Story Title'),
            excerpt: PlainText::fromUntrusted('A short excerpt'),
            siteName: PlainText::fromUntrusted('Example News'),
            content: '<p>Body</p>',
            sourceUrl: 'https://ex.com/a',
        );
    }
}
