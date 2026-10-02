<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;

final class SafeDocumentTest extends TestCase
{
    public function test_stores_purified_html_and_metadata(): void
    {
        $title = PlainText::fromUntrusted('Tom & Jerry');
        $excerpt = PlainText::fromUntrusted('A short line');
        $siteName = PlainText::fromUntrusted('Example');

        $document = new SafeDocument(
            '<p>x</p>',
            $title,
            $excerpt,
            $siteName,
            'https://example.com/article',
        );

        self::assertSame('<p>x</p>', $document->html);
        self::assertSame($title, $document->title);
        self::assertSame('Tom & Jerry', $document->title->raw());
        self::assertSame($excerpt, $document->excerpt);
        self::assertSame($siteName, $document->siteName);
        self::assertSame('https://example.com/article', $document->sourceUrl);
    }

    public function test_allows_empty_html_and_missing_optional_metadata(): void
    {
        $document = new SafeDocument('', PlainText::fromUntrusted(''), null, null, 'https://example.com/a');

        self::assertSame('', $document->html);
        self::assertSame('', $document->title->raw());
        self::assertNull($document->excerpt);
        self::assertNull($document->siteName);
    }
}
