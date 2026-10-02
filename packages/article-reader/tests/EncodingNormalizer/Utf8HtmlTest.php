<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class Utf8HtmlTest extends TestCase
{
    public function test_stores_all_fields(): void
    {
        $html = new Utf8Html(
            html: '<html></html>',
            sourceUrl: 'https://example.com/article',
            sourceEncoding: 'ISO-8859-1',
            source: EncodingSource::HttpHeader,
        );

        self::assertSame('<html></html>', $html->html);
        self::assertSame('https://example.com/article', $html->sourceUrl);
        self::assertSame('ISO-8859-1', $html->sourceEncoding);
        self::assertSame(EncodingSource::HttpHeader, $html->source);
    }

    public function test_empty_html_is_allowed(): void
    {
        $html = new Utf8Html(
            html: '',
            sourceUrl: 'https://example.com/',
            sourceEncoding: 'UTF-8',
            source: EncodingSource::Utf8Default,
        );

        self::assertSame('', $html->html);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_invalid_values(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): Utf8Html, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'invalid utf-8' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: "\xC3\x28",
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'html must be valid UTF-8',
            ],
            'leading utf-8 bom' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: "\xEF\xBB\xBF<html></html>",
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::Bom,
                ),
                'html must not start with a UTF-8 BOM',
            ],
            'empty sourceUrl' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: '',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceUrl must not be empty',
            ],
            'blank sourceUrl' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: '   ',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceUrl must not be empty',
            ],
            'empty sourceEncoding' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: '',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceEncoding must not be empty',
            ],
        ];
    }
}
