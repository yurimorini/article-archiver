<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractStatus;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class ArticleExtractorTest extends TestCase
{
    public function test_extracts_article_and_drops_chrome(): void
    {
        $result = (new ArticleExtractor())->extract($this->page($this->articleHtml()));

        self::assertSame(ExtractStatus::Ok, $result->status);
        self::assertTrue($result->isOk());
        self::assertNotNull($result->document);
        self::assertSame('Story Title', $result->document->title->raw());
        self::assertNotNull($result->document->excerpt);
        self::assertSame('A short excerpt', $result->document->excerpt->raw());
        self::assertNotNull($result->document->siteName);
        self::assertSame('Example News', $result->document->siteName->raw());
        self::assertSame('https://ex.com/a', $result->document->sourceUrl);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $result->document->content);
        self::assertStringContainsString('href="https://ex.com/x"', $result->document->content);
        self::assertStringNotContainsString('Home About Contact', $result->document->content);
        self::assertStringNotContainsString('Copyright 2026', $result->document->content);
    }

    #[DataProvider('blankHtml')]
    public function test_blank_html_is_no_content(string $html): void
    {
        $result = (new ArticleExtractor())->extract($this->page($html, 'https://ex.com/empty'));

        self::assertSame(ExtractStatus::NoContent, $result->status);
        self::assertTrue($result->isNoContent());
        self::assertNull($result->document);
        self::assertNotNull($result->title);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame('https://ex.com/empty', $result->sourceUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankHtml(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'whitespace' => ["\n\t  "],
        ];
    }

    private function page(string $html, string $sourceUrl = 'https://ex.com/a'): Utf8Html
    {
        return new Utf8Html(
            html: $html,
            sourceUrl: $sourceUrl,
            sourceEncoding: 'UTF-8',
            source: EncodingSource::Utf8Default,
        );
    }

    /**
     * This helper builds a page whose article text is long enough for the default character threshold of 500.
     *
     * Twenty repetitions are about 900 characters. On readability.php 4.1.0 that length keeps the article and drops the nav and footer.
     */
    private function articleHtml(): string
    {
        $paragraph = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);

        return '<!DOCTYPE html><html><head><title>Story Title</title>'
            . '<meta property="og:description" content="A short excerpt">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body>'
            . '<nav>Home About Contact</nav>'
            . '<article><h1>Story Title</h1><p>' . $paragraph . '</p>'
            . '<p><a href="/x">more</a></p></article>'
            . '<footer>Copyright 2026</footer>'
            . '</body></html>';
    }
}
