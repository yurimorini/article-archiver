<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Response as BrowserResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yumo\Eleanor\Request\EnrichedRequest;

/**
 * Proves the API request stores an enriched request around the action.
 */
final class LogApiRequestTest extends WebTestCase
{
    private static bool $runtimeModeSet = false;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $server
     */
    protected static function createClient(array $options = [], array $server = []): LoggingBrowser
    {
        if (static::$booted) {
            throw new \LogicException(\sprintf('Booting the kernel before calling "%s()" is not supported, the kernel should only be booted once.', __METHOD__));
        }

        if (!isset($_SERVER['APP_RUNTIME_MODE'])) {
            $_SERVER['APP_RUNTIME_MODE'] = 'web=1';
            self::$runtimeModeSet = true;
        }

        $kernel = static::bootKernel($options);
        $client = new LoggingBrowser($kernel);
        $client->setServerParameters($server);

        return $client;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (self::$runtimeModeSet) {
            unset($_SERVER['APP_RUNTIME_MODE']);
            self::$runtimeModeSet = false;
        }
    }

    public function test_post_stores_the_sent_id_agent_and_referer(): void
    {
        $client = static::createClient();
        $this->postArchives($client, [
            'HTTP_X_REQUEST_ID' => 'kept-id',
            'HTTP_USER_AGENT' => 'ArchiveClient/1',
            'HTTP_REFERER' => 'https://example.test/from',
        ]);

        $enriched = $this->enriched($client->getRequest());
        self::assertSame('kept-id', $enriched->requestId);
        self::assertSame('ArchiveClient/1', $enriched->userAgent);
        self::assertSame('https://example.test/from', $enriched->referer);
        $response = $this->symfonyResponse($client->getResponse());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('content-type'));
        self::assertSame('{"ok":true}', $this->body($client->getInternalResponse()));
    }

    public function test_missing_id_is_a_uuid(): void
    {
        $client = static::createClient();
        $this->postArchives($client);

        $id = $this->enriched($client->getRequest())->requestId;
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function test_rejected_first_id_stays_rejected(): void
    {
        $client = static::createClient();
        $this->postArchives($client, [
            'HTTP_X_REQUEST_ID' => ['bad id', 'other-id'],
        ]);

        $request = $this->httpRequest($client->getRequest());
        self::assertSame(['bad id', 'other-id'], $request->headers->all('X-Request-Id'));
        $id = $this->enriched($request)->requestId;
        self::assertNotSame('other-id', $id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function test_valid_first_id_wins_over_a_second_id(): void
    {
        $client = static::createClient();
        $this->postArchives($client, [
            'HTTP_X_REQUEST_ID' => ['kept-id', 'other-id'],
        ]);

        $request = $this->httpRequest($client->getRequest());
        self::assertSame(['kept-id', 'other-id'], $request->headers->all('X-Request-Id'));
        self::assertSame('kept-id', $this->enriched($request)->requestId);
    }

    public function test_missing_user_agent_and_referer_are_null(): void
    {
        $client = static::createClient();
        $this->postArchives($client);

        $enriched = $this->enriched($client->getRequest());
        self::assertNull($enriched->userAgent);
        self::assertNull($enriched->referer);
    }

    public function test_empty_user_agent_and_referer_are_null(): void
    {
        $client = static::createClient();
        $this->postArchives($client, [
            'HTTP_USER_AGENT' => '',
            'HTTP_REFERER' => '',
        ]);

        $enriched = $this->enriched($client->getRequest());
        self::assertNull($enriched->userAgent);
        self::assertNull($enriched->referer);
    }

    public function test_head_leaves_the_attribute_absent(): void
    {
        $client = static::createClient();
        $client->request('HEAD', '/api/archives');

        self::assertSame(405, $this->symfonyResponse($client->getResponse())->getStatusCode());
        self::assertFalse($this->httpRequest($client->getRequest())->attributes->has(EnrichedRequest::class));
    }

    public function test_unmatched_post_leaves_the_attribute_absent(): void
    {
        $client = static::createClient();
        $client->request('POST', '/no-such');

        self::assertSame(404, $this->symfonyResponse($client->getResponse())->getStatusCode());
        self::assertFalse($this->httpRequest($client->getRequest())->attributes->has(EnrichedRequest::class));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function postArchives(LoggingBrowser $client, array $server = []): void
    {
        $server['CONTENT_TYPE'] = 'application/json';
        $client->request('POST', '/api/archives', server: $server, content: '{"url":"https://example.test/a"}');
    }

    private function httpRequest(object $request): Request
    {
        if (!$request instanceof Request) {
            self::fail('The client request must be an HTTP request.');
        }

        return $request;
    }

    private function symfonyResponse(object $response): Response
    {
        if (!$response instanceof Response) {
            self::fail('The client response must be an HTTP response.');
        }

        return $response;
    }

    private function enriched(object $request): EnrichedRequest
    {
        $value = $this->httpRequest($request)->attributes->get(EnrichedRequest::class);

        if (!class_exists(EnrichedRequest::class) || !$value instanceof EnrichedRequest) {
            self::fail('The request has no EnrichedRequest attribute.');
        }

        return $value;
    }

    private function body(object $response): string
    {
        if (!$response instanceof BrowserResponse) {
            self::fail('The client response must be a browser response.');
        }

        return $response->getContent();
    }
}
