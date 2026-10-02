<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

final class ReadableDocumentTest extends TestCase
{
    public function test_stores_article_fields(): void
    {
        $title = PlainText::fromUntrusted('Story Title');
        $excerpt = PlainText::fromUntrusted('A short excerpt');
        $document = new ReadableDocument(
            title: $title,
            excerpt: $excerpt,
            siteName: null,
            content: '<p>Body</p>',
            sourceUrl: 'https://ex.com/a',
        );

        self::assertSame($title, $document->title);
        self::assertSame($excerpt, $document->excerpt);
        self::assertNull($document->siteName);
        self::assertSame('<p>Body</p>', $document->content);
        self::assertSame('https://ex.com/a', $document->sourceUrl);
    }
}
