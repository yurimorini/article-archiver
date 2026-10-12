<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves POST /api/archives through the kernel.
 */
final class CreateArchiveRouteTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function acceptedPostProvider(): iterable
    {
        yield 'string url' => ['/api/archives?ignored=1', ['CONTENT_TYPE' => 'application/json; charset=utf-8'], '{"url":"https://example.com/a"}'];
        yield 'empty url' => ['/api/archives', ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], '{"url":""}'];
        yield 'extra property' => ['/api/archives', ['CONTENT_TYPE' => 'application/json'], '{"url":"https://example.com/a","extra":1}'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('acceptedPostProvider')]
    public function test_accepted_post_returns_the_empty_route_stub(string $uri, array $server, string $content): void
    {
        $client = static::createClient();
        $client->request('POST', $uri, [], [], $server, $content);

        self::assertRouteSame('create_archive');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('{"ok":true}', $this->responseBody($client->getInternalResponse()));
    }

    /**
     * @return iterable<string, array{int, array<string, string>, ?string}>
     */
    public static function refusedPostProvider(): iterable
    {
        yield 'malformed json' => [400, ['CONTENT_TYPE' => 'application/json'], '{'];
        yield 'missing url' => [400, ['CONTENT_TYPE' => 'application/json'], '{}'];
        yield 'url not a string' => [400, ['CONTENT_TYPE' => 'application/json'], '{"url":1}'];
        yield 'json value not the object' => [400, ['CONTENT_TYPE' => 'application/json'], '[]'];
        yield 'missing content type' => [415, [], '{"url":"https://example.com/a"}'];
        yield 'text plain' => [415, ['CONTENT_TYPE' => 'text/plain'], 'not-json'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('refusedPostProvider')]
    public function test_refused_post_returns_json_status(int $status, array $server, ?string $content): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/archives', [], [], $server, $content);

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function test_head_is_method_not_allowed(): void
    {
        $client = static::createClient();
        $client->request('HEAD', '/api/archives');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_get_is_method_not_allowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/archives');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_post_with_trailing_slash_misses_the_route(): void
    {
        $client = static::createClient();
        $client->followRedirects(false);
        $client->request('POST', '/api/archives/');

        self::assertResponseStatusCodeSame(404);
        self::assertNotSame('create_archive', $this->matchedRoute($client->getRequest()));
    }

    public function test_unprefixed_path_does_not_match_create_archive(): void
    {
        $client = static::createClient();
        $client->request('POST', '/archives');

        self::assertNotSame('create_archive', $this->matchedRoute($client->getRequest()));
    }

    private function matchedRoute(object $request): mixed
    {
        if (!$request instanceof Request) {
            self::fail('The client request must be an HTTP request.');
        }

        return $request->attributes->get('_route');
    }

    private function responseBody(object $response): string
    {
        if (!$response instanceof Response) {
            self::fail('The client response must be a browser response.');
        }

        return $response->getContent();
    }
}
