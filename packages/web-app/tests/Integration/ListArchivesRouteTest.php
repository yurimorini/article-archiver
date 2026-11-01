<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\Bearer;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves GET /api/archives through the kernel with a fake fetcher.
 */
final class ListArchivesRouteTest extends WebTestCase
{
    private string $directories;

    protected function setUp(): void
    {
        $this->directories = ArchiveDirectories::isolate();
        parent::setUp();
    }

    public function test_one_archive_returns_the_default_page(): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article());
        $this->postUrl($client, 'https://example.com/a');

        $client->request('GET', '/api/archives', server: $this->authorized([]));

        self::assertSame('list_archives', $this->matchedRoute($client->getRequest()));
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = $this->decodedBody($client);
        self::assertSame('archived', $body['sort']);
        self::assertSame('desc', $body['dir']);
        self::assertSame(1, $body['page']);
        self::assertSame(10, $body['size']);
        self::assertSame(1, $body['total']);
        self::assertNull($body['next']);
        self::assertCount(1, $body['items']);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertSame(1, $item['id']);
        self::assertSame('https://example.com/a', $item['url']);
        self::assertSame('Title', $item['title']);
        self::assertNull($item['excerpt']);
        self::assertNull($item['siteName']);
        self::assertSame(
            ['url', 'title', 'excerpt', 'siteName', 'id', 'archived'],
            array_keys($item),
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['archived']);
        self::assertArrayNotHasKey('html', $item);
        self::assertStringContainsString('"excerpt":null,"siteName":null', $this->responseBody($client));
    }

    public function test_eleven_archives_link_the_next_page_and_stop_after_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        for ($number = 1; $number <= 11; ++$number) {
            $url = 'https://example.com/' . $number;
            $this->fetcher()->willReturn($url, PipelineFixtures::article('Title ' . $number));
            $this->postUrl($client, $url);
            self::assertResponseStatusCodeSame(201);
        }

        $client->request('GET', '/api/archives', server: $this->authorized([]));

        $first = $this->decodedBody($client);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(10, $first['size']);
        self::assertSame(11, $first['total']);
        self::assertCount(10, $first['items']);
        self::assertSame('/api/archives?sort=archived&dir=desc&page=2&size=10', $first['next']);

        $client->request('GET', '/api/archives?page=2&size=10', server: $this->authorized([]));

        $second = $this->decodedBody($client);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(10, $second['size']);
        self::assertSame(11, $second['total']);
        self::assertCount(1, $second['items']);
        self::assertNull($second['next']);

        $client->request('GET', '/api/archives?page=3&size=10', server: $this->authorized([]));

        $third = $this->decodedBody($client);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(11, $third['total']);
        self::assertSame([], $third['items']);
        self::assertNull($third['next']);
    }

    public function test_a_missing_database_is_an_empty_page_and_creates_no_file(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/archives', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedBody($client);
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
        self::assertNull($body['next']);
        self::assertSame([], glob($this->directories . '/data/*.sqlite') ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueryProvider(): iterable
    {
        yield 'sort' => ['/api/archives?sort=created_at'];
        yield 'dir' => ['/api/archives?dir=ASC'];
        yield 'page zero' => ['/api/archives?page=0'];
        yield 'page plus' => ['/api/archives?page=+1'];
        yield 'size zero' => ['/api/archives?size=0'];
        yield 'size above max' => ['/api/archives?size=101'];
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_an_illegal_query_is_invalid_query(string $uri): void
    {
        $client = static::createClient();

        $client->request('GET', $uri, server: $this->authorized([]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            ['code' => 'invalid_query', 'message' => 'A parameter has an invalid value.'],
            $this->decodedBody($client),
        );
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_size_at_the_maximum_is_accepted(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/archives?size=100', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedBody($client);
        self::assertSame(100, $body['size']);
        self::assertSame(0, $body['total']);
        self::assertNull($body['next']);
    }

    public function test_an_unknown_query_key_is_ignored(): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article());
        $this->postUrl($client, 'https://example.com/a');

        $client->request('GET', '/api/archives?unknown=1', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedBody($client);
        self::assertSame('archived', $body['sort']);
        self::assertSame('desc', $body['dir']);
        self::assertSame(1, $body['page']);
        self::assertSame(10, $body['size']);
        self::assertSame(1, $body['total']);
        self::assertNull($body['next']);
    }

    public function test_a_repeated_page_is_invalid_query(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/archives', ['page' => ['1', '2']], server: $this->authorized([]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            ['code' => 'invalid_query', 'message' => 'A parameter has an invalid value.'],
            $this->decodedBody($client),
        );
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_null_excerpt_site_quote_and_raw_slash_stay_in_the_json(): void
    {
        $title = 'Say "Café"';
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a/b', PipelineFixtures::article($title));
        $this->postUrl($client, 'https://example.com/a/b');

        $client->request('GET', '/api/archives', server: $this->authorized([]));

        $raw = $this->responseBody($client);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['items']);
        $item = $decoded['items'][0];
        self::assertIsArray($item);
        self::assertSame($title, $item['title']);
        self::assertSame('https://example.com/a/b', $item['url']);
        self::assertNull($item['excerpt']);
        self::assertNull($item['siteName']);
        self::assertStringContainsString('"excerpt":null,"siteName":null', $raw);
        self::assertStringContainsString('Say \\"Café\\"', $raw);
        self::assertStringContainsString('https://example.com/a/b', $raw);
        self::assertStringContainsString('Café', $raw);
        self::assertStringNotContainsString('\\u00e9', $raw);
        self::assertStringNotContainsString('\\/', $raw);
    }

    public function test_an_empty_title_is_passed_through(): void
    {
        $client = static::createClient();
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article(''));
        $this->postUrl($client, 'https://example.com/a');

        $client->request('GET', '/api/archives', server: $this->authorized([]));

        $body = $this->decodedBody($client);
        self::assertIsArray($body['items']);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertSame('', $item['title']);
    }

    public function test_head_returns_the_get_status_and_an_empty_body(): void
    {
        $client = static::createClient();
        $server = $this->authorized([]);
        $client->request('GET', '/api/archives', server: $server);
        $status = $client->getResponse()->getStatusCode();
        $contentType = (string) $client->getResponse()->headers->get('content-type');

        $client->request('HEAD', '/api/archives', server: $server);

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame($contentType, (string) $client->getResponse()->headers->get('content-type'));
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_a_missing_bearer_is_unauthenticated(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/archives');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            ['code' => 'unauthenticated', 'message' => 'The request is not authenticated.'],
            $this->decodedBody($client),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedMethodProvider(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('disallowedMethodProvider')]
    public function test_put_and_delete_are_method_not_allowed(string $method): void
    {
        $client = static::createClient();

        $client->request($method, '/api/archives');

        $allow = (string) $client->getResponse()->headers->get('Allow');
        self::assertResponseStatusCodeSame(405);
        self::assertStringContainsString('GET', $allow);
        self::assertStringContainsString('HEAD', $allow);
        self::assertStringContainsString('POST', $allow);
        self::assertSame(
            ['code' => 'method_not_allowed', 'message' => 'The method is not allowed for this path.'],
            $this->decodedBody($client),
        );
    }

    /**
     * Reads the fake fetcher from the test container.
     *
     * A test that posts twice and needs the fake to keep its table of outcomes must call
     * `$client->disableReboot()` first. Otherwise the browser reboots the kernel before the second
     * request and the container builds a new fake. The archives survive a reboot, because they live
     * in the isolated data directory.
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
            $this->authorized(['CONTENT_TYPE' => 'application/json']),
            json_encode(['url' => $url], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    private function authorized(array $server): array
    {
        return [...$server, 'HTTP_AUTHORIZATION' => Bearer::header()];
    }

    private function matchedRoute(object $request): mixed
    {
        if (!$request instanceof Request) {
            self::fail('The client request must be an HTTP request.');
        }

        return $request->attributes->get('_route');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedBody(KernelBrowser $client): array
    {
        $decoded = json_decode($this->responseBody($client), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function responseBody(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }
}
