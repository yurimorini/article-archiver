<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\EncodingNormalizer\EncodingOutcome;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\HttpFetcher\FetchedPage;

final class EncodingNormalizerTest extends TestCase
{
    public function test_header_utf8_ascii_body_is_ok(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertSame('utf-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }

    public function test_quoted_and_case_insensitive_header_charset(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; CHARSET="UTF-8"');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
    }

    public function test_header_iso_8859_1_latin1_bytes_become_utf8_e_acute(): void
    {
        $page = $this->page("<html>caf\xE9</html>", 'text/html; charset=ISO-8859-1');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertSame('ISO-8859-1', $outcome->html->sourceEncoding);
        self::assertSame('<html>café</html>', $outcome->html->html);
    }

    public function test_meta_charset_only(): void
    {
        $body = "<html><head><meta charset=\"windows-1252\"></head><body>caf\xE9</body></html>";
        $page = $this->page($body, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Meta, $outcome->html->source);
        self::assertSame('windows-1252', $outcome->html->sourceEncoding);
        self::assertStringContainsString('café', $outcome->html->html);
        self::assertStringContainsString('charset="windows-1252"', $outcome->html->html);
    }

    public function test_html4_http_equiv_meta_charset(): void
    {
        $body = '<html><head><meta http-equiv="content-type" content="text/html; charset=ISO-8859-1"></head>'
            . "<body>caf\xE9</body></html>";
        $page = $this->page($body, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Meta, $outcome->html->source);
        self::assertStringContainsString('café', $outcome->html->html);
    }

    public function test_http_charset_wins_over_conflicting_meta(): void
    {
        $body = "<html><head><meta charset=\"ISO-8859-1\"></head><body>caf\xC3\xA9</body></html>";
        $page = $this->page($body, 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertStringContainsString('café', $outcome->html->html);
        self::assertStringNotContainsString('Ã', $outcome->html->html);
    }

    public function test_utf8_bom_is_stripped_and_wins_over_http_charset(): void
    {
        $page = $this->page("\xEF\xBB\xBF<html>ok</html>", 'text/html; charset=ISO-8859-1');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Bom, $outcome->html->source);
        self::assertSame('UTF-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }

    public function test_no_declaration_valid_utf8_uses_default(): void
    {
        $page = $this->page('<html>café</html>', 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Utf8Default, $outcome->html->source);
        self::assertSame('UTF-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>café</html>', $outcome->html->html);
    }

    public function test_empty_body_with_utf8_header_is_ok(): void
    {
        $page = $this->page('', 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame('', $outcome->html->html);
    }

    public function test_source_url_comes_from_request_uri(): void
    {
        $page = $this->page(
            '<html>ok</html>',
            'text/html; charset=utf-8',
            'https://example.net/posts/1?q=1',
        );
        $outcome = (new EncodingNormalizer())->normalize($page);

        self::assertSame('https://example.net/posts/1?q=1', $outcome->html->sourceUrl);
    }

    /**
     * Builds a successful fetch fixture. Status is unused by the normalizer and stays 200.
     */
    private function page(
        string $body,
        string $contentType,
        string $requestUri = 'https://example.com/article',
    ): FetchedPage {
        return new FetchedPage($body, $contentType, 200, $requestUri);
    }

    /**
     * Asserts the outcome is clean UTF-8 HTML for `$page`, with provenance copied from the fetch.
     */
    private function assertOkUtf8(EncodingOutcome $outcome, FetchedPage $page): void
    {
        self::assertTrue($outcome->isOk());
        self::assertNull($outcome->warning);
        self::assertTrue(mb_check_encoding($outcome->html->html, 'UTF-8'));
        self::assertSame($page->requestUri, $outcome->html->sourceUrl);
        self::assertFalse(str_starts_with($outcome->html->html, "\xEF\xBB\xBF"));
    }
}
