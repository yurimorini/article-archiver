<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\HttpFoundation\Request;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves POST /api/archives through the kernel with a fake fetcher.
 */
final class CreateArchiveRouteTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function acceptedPostProvider(): iterable
    {
        yield 'string url' => ['/api/archives?ignored=1', ['CONTENT_TYPE' => 'application/json; charset=utf-8'], '{"url":"https://example.com/a"}'];
        yield 'accept html' => ['/api/archives', ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], '{"url":"https://example.com/a"}'];
        yield 'extra property' => ['/api/archives', ['CONTENT_TYPE' => 'application/json'], '{"url":"https://example.com/a","extra":1}'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('acceptedPostProvider')]
    public function test_new_url_returns_201_and_the_archive_json(string $uri, array $server, string $content): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::success());
        $client->request('POST', $uri, [], [], $server, $content);

        self::assertRouteSame('create_archive');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('{"url":"https://example.com/a","title":"Title"}', $this->responseBody($client->getInternalResponse()));
    }

    public function test_same_url_again_returns_200_with_the_same_body_and_one_fetch(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::success());

        $this->postUrl($client, 'https://example.com/a');
        $first = $this->responseBody($client->getInternalResponse());
        $this->postUrl($client, 'https://example.com/a');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame($first, $this->responseBody($client->getInternalResponse()));
        self::assertSame(1, $this->fetcher()->fetchCount());
    }

    public function test_a_different_url_returns_201_and_fetches_again(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::success('First'));
        $this->fetcher()->willReturn('https://example.com/b', PipelineFixtures::success('Second'));

        $this->postUrl($client, 'https://example.com/a');
        $this->postUrl($client, 'https://example.com/b');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('{"url":"https://example.com/b","title":"Second"}', $this->responseBody($client->getInternalResponse()));
        self::assertSame(2, $this->fetcher()->fetchCount());
    }

    public function test_title_with_quote_and_backslash_decodes_to_the_same_title(): void
    {
        $title = 'He said "hi" \\ and left';
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::success($title));

        $this->postUrl($client, 'https://example.com/a');

        $decoded = json_decode($this->responseBody($client->getInternalResponse()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame($title, $decoded['title']);
    }

    public function test_missing_title_is_an_empty_string(): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::success(''));

        $this->postUrl($client, 'https://example.com/a');

        self::assertSame('{"url":"https://example.com/a","title":""}', $this->responseBody($client->getInternalResponse()));
    }

    public function test_non_ascii_title_and_slashes_stay_readable(): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a/b', PipelineFixtures::success('Café', sourceUrl: 'https://example.com/a/b'));

        $this->postUrl($client, 'https://example.com/a/b');

        self::assertSame('{"url":"https://example.com/a/b","title":"Café"}', $this->responseBody($client->getInternalResponse()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedArchiveProvider(): iterable
    {
        yield 'empty url' => [''];
        yield 'spaces' => [' '];
        yield 'not a url' => ['not-a-url'];
        yield 'no article' => ['https://example.com/empty'];
        yield 'fetch failure' => ['https://example.com/broken'];
    }

    #[DataProvider('failedArchiveProvider')]
    public function test_failed_archive_is_not_success_json_and_not_a_problem_document(string $url): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/empty', PipelineFixtures::noContent());
        $this->fetcher()->willThrow('https://example.com/broken', new \RuntimeException('Broken'));

        $this->postUrl($client, $url);

        self::assertSame(1, $this->fetcher()->fetchCount());
        $response = $client->getResponse();
        self::assertNotSame(201, $response->getStatusCode());
        self::assertNotSame(200, $response->getStatusCode());
        self::assertNotSame('application/problem+json', $response->headers->get('content-type'));
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertFalse(is_array($decoded) && array_key_exists('url', $decoded));
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

    /**
     * Reads the fake fetcher from the test container.
     *
     * A test that posts twice must call `$client->disableReboot()` first. Otherwise the browser reboots
     * the kernel before the second request, and the fake and the in-memory shelf would be lost.
     */
    private function fetcher(): FakeArticleFetcher
    {
        $fetcher = static::getContainer()->get(FakeArticleFetcher::class);
        self::assertInstanceOf(FakeArticleFetcher::class, $fetcher);

        return $fetcher;
    }

    private function postUrl(KernelBrowser $client, string $url): void
    {
        $client->request(
            'POST',
            '/api/archives',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['url' => $url], JSON_THROW_ON_ERROR),
        );
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
