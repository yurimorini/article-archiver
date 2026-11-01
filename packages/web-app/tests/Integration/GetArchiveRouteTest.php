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
 * Proves GET /api/archives/{id} and GET /api/archives/{id}.html through the kernel.
 */
final class GetArchiveRouteTest extends WebTestCase
{
    private const URL = 'https://example.com/a';

    private const HTML = 'say "hi" <b>x</b>';

    private string $directories;

    protected function setUp(): void
    {
        $this->directories = ArchiveDirectories::isolate();
        parent::setUp();
    }

    public function test_one_archive_returns_the_metadata_document(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', '/api/archives/1', server: $this->authorized([]));

        self::assertSame('read_archive', $this->matchedRoute($client->getRequest()));
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = $this->decodedBody($client);
        self::assertSame(
            ['url', 'title', 'excerpt', 'siteName', 'id', 'archived'],
            array_keys($body),
        );
        self::assertSame(self::URL, $body['url']);
        self::assertSame('Title', $body['title']);
        self::assertNull($body['excerpt']);
        self::assertNull($body['siteName']);
        self::assertSame(1, $body['id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['archived']);
        self::assertArrayNotHasKey('html', $body);
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_include_content_true_appends_the_stored_html(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', '/api/archives/1?includeContent=true', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedBody($client);
        self::assertSame(
            ['url', 'title', 'excerpt', 'siteName', 'id', 'archived', 'html'],
            array_keys($body),
        );
        self::assertSame(self::HTML, $body['html']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedIncludeContentProvider(): iterable
    {
        yield 'false' => ['/api/archives/1?includeContent=false'];
        yield 'one' => ['/api/archives/1?includeContent=1'];
        yield 'TRUE' => ['/api/archives/1?includeContent=TRUE'];
    }

    #[DataProvider('rejectedIncludeContentProvider')]
    public function test_a_rejected_include_content_is_invalid_query(string $uri): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', $uri, server: $this->authorized([]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            ['code' => 'invalid_query', 'message' => 'A parameter has an invalid value.'],
            $this->decodedBody($client),
        );
    }

    public function test_a_repeated_include_content_is_invalid_query(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request(
            'GET',
            '/api/archives/1',
            ['includeContent' => ['true', 'false']],
            server: $this->authorized([]),
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            ['code' => 'invalid_query', 'message' => 'A parameter has an invalid value.'],
            $this->decodedBody($client),
        );
    }

    public function test_an_unknown_query_key_is_ignored(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', '/api/archives/1?sort=no', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedBody($client);
        self::assertSame(
            ['url', 'title', 'excerpt', 'siteName', 'id', 'archived'],
            array_keys($body),
        );
        self::assertSame(self::URL, $body['url']);
        self::assertSame('Title', $body['title']);
        self::assertSame(1, $body['id']);
        self::assertArrayNotHasKey('html', $body);
    }

    public function test_the_html_route_returns_the_stored_markup(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', '/api/archives/1.html', server: $this->authorized([]));

        self::assertSame('read_archive_html', $this->matchedRoute($client->getRequest()));
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSame(self::HTML, $this->responseBody($client));
    }

    public function test_the_html_route_ignores_the_query_string(): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', '/api/archives/1.html?includeContent=false', server: $this->authorized([]));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSame(self::HTML, $this->responseBody($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingArchiveProvider(): iterable
    {
        yield 'metadata' => ['/api/archives/999'];
        yield 'content' => ['/api/archives/999?includeContent=true'];
        yield 'html' => ['/api/archives/999.html'];
    }

    #[DataProvider('missingArchiveProvider')]
    public function test_a_missing_archive_is_not_found(string $uri): void
    {
        $client = static::createClient();
        $this->archiveOne($client);

        $client->request('GET', $uri, server: $this->authorized([]));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(
            ['code' => 'archive_not_found', 'message' => 'The archive does not exist.'],
            $this->decodedBody($client),
        );
    }

    public function test_a_missing_database_is_not_found_and_creates_no_file(): void
    {
        $client = static::createClient();

        foreach (['/api/archives/999', '/api/archives/999?includeContent=true', '/api/archives/999.html'] as $uri) {
            $client->request('GET', $uri, server: $this->authorized([]));

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('content-type', 'application/json');
            self::assertSame(
                ['code' => 'archive_not_found', 'message' => 'The archive does not exist.'],
                $this->decodedBody($client),
            );
        }

        self::assertSame([], glob($this->directories . '/data/*.sqlite') ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'word' => ['/api/archives/abc'];
        yield 'zero' => ['/api/archives/0'];
        yield 'negative' => ['/api/archives/-3'];
        yield 'decimal' => ['/api/archives/1.5'];
        yield 'decimal html' => ['/api/archives/1.5.html'];
        yield 'leading zero' => ['/api/archives/01'];
        yield 'word html' => ['/api/archives/abc.html'];
    }

    #[DataProvider('invalidIdProvider')]
    public function test_an_illegal_id_is_invalid_query(string $uri): void
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPathProvider(): iterable
    {
        yield 'trailing slash' => ['/api/archives/'];
        yield 'json suffix' => ['/api/archives/17.json'];
        yield 'unknown' => ['/api/no-such'];
    }

    #[DataProvider('unknownPathProvider')]
    public function test_an_unknown_path_is_not_found(string $uri): void
    {
        $client = static::createClient();

        $client->request('GET', $uri, server: $this->authorized([]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            ['code' => 'not_found', 'message' => 'The path does not exist.'],
            $this->decodedBody($client),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headPathProvider(): iterable
    {
        yield 'json' => ['/api/archives/1'];
        yield 'html' => ['/api/archives/1.html'];
    }

    #[DataProvider('headPathProvider')]
    public function test_head_returns_the_get_status_and_an_empty_body(string $uri): void
    {
        $client = static::createClient();
        $this->archiveOne($client);
        $server = $this->authorized([]);
        $client->request('GET', $uri, server: $server);
        $status = $client->getResponse()->getStatusCode();
        $contentType = (string) $client->getResponse()->headers->get('content-type');

        $client->request('HEAD', $uri, server: $server);

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame($contentType, (string) $client->getResponse()->headers->get('content-type'));
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function disallowedMethodProvider(): iterable
    {
        yield 'PUT json' => ['PUT', '/api/archives/1'];
        yield 'DELETE json' => ['DELETE', '/api/archives/1'];
        yield 'PUT html' => ['PUT', '/api/archives/1.html'];
        yield 'DELETE html' => ['DELETE', '/api/archives/1.html'];
    }

    #[DataProvider('disallowedMethodProvider')]
    public function test_put_and_delete_are_method_not_allowed(string $method, string $uri): void
    {
        $client = static::createClient();

        $client->request($method, $uri);

        $allow = (string) $client->getResponse()->headers->get('Allow');
        self::assertResponseStatusCodeSame(405);
        self::assertStringContainsString('GET', $allow);
        self::assertStringContainsString('HEAD', $allow);
        self::assertStringNotContainsString('POST', $allow);
        self::assertSame(
            ['code' => 'method_not_allowed', 'message' => 'The method is not allowed for this path.'],
            $this->decodedBody($client),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthenticatedPathProvider(): iterable
    {
        yield 'json' => ['/api/archives/1'];
        yield 'html' => ['/api/archives/1.html'];
    }

    #[DataProvider('unauthenticatedPathProvider')]
    public function test_a_missing_bearer_is_unauthenticated(string $uri): void
    {
        $client = static::createClient();

        $client->request('GET', $uri);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            ['code' => 'unauthenticated', 'message' => 'The request is not authenticated.'],
            $this->decodedBody($client),
        );
    }

    private function archiveOne(KernelBrowser $client): void
    {
        $this->fetcher()->willReturn(self::URL, PipelineFixtures::article('Title', null, null, self::HTML));
        $this->postUrl($client, self::URL);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Reads the fake fetcher from the test container.
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
