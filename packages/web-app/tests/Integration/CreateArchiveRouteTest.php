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
     * @return iterable<string, array{string, array<string, string>, ?string}>
     */
    public static function successfulPostProvider(): iterable
    {
        yield 'no body' => ['/api/archives', ['HTTP_ACCEPT' => 'text/html'], null];
        yield 'non-json body' => ['/api/archives', ['CONTENT_TYPE' => 'text/plain'], 'not-json'];
        yield 'json url pointer' => ['/api/archives?ignored=1', ['CONTENT_TYPE' => 'application/json'], '{"url":"https://example.com/a"}'];
        yield 'url not a string' => ['/api/archives', ['CONTENT_TYPE' => 'application/json'], '{"url":1}'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('successfulPostProvider')]
    public function test_post_returns_the_empty_route_stub(string $uri, array $server, ?string $content): void
    {
        $client = static::createClient();
        $client->request('POST', $uri, [], [], $server, $content);

        self::assertRouteSame('create_archive');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('{"ok":true}', $this->responseBody($client->getInternalResponse()));
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
