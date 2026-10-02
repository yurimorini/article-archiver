<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\FetchedPage;

final class FetchedPageTest extends TestCase
{
    public function test_stores_all_fields(): void
    {
        $page = new FetchedPage(
            body: '<html></html>',
            contentType: 'text/html; charset=utf-8',
            statusCode: 200,
            requestUri: 'https://example.com/',
        );

        self::assertSame('<html></html>', $page->body);
        self::assertSame('text/html; charset=utf-8', $page->contentType);
        self::assertSame(200, $page->statusCode);
        self::assertSame('https://example.com/', $page->requestUri);
    }

    public function test_empty_body_is_allowed(): void
    {
        $page = new FetchedPage('', 'text/html', 204, 'https://example.com/');
        self::assertSame('', $page->body);
    }
}
