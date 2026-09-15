<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

final class HttpFetcherTest extends TestCase
{
    private function target(): SafeFetchTarget
    {
        return new SafeFetchTarget(
            requestUri: 'https://example.com/article',
            host: 'example.com',
            port: 443,
            ips: ['203.0.113.10'],
        );
    }

    /**
     * @param list<mixed> $queue
     * @param-out MockHandler $mockOut
     */
    private function clientWithResponses(array $queue, ?MockHandler &$mockOut = null): Client
    {
        $mock = new MockHandler($queue);
        $mockOut = $mock;
        $stack = HandlerStack::create($mock);

        return new Client([
            'handler' => $stack,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public function test_success_returns_fetched_page(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>ok</html>'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('<html>ok</html>', $page->body);
        self::assertSame('text/html; charset=utf-8', $page->contentType);
        self::assertSame(200, $page->statusCode);
        self::assertSame('https://example.com/article', $page->requestUri);
    }

    public function test_success_allows_xhtml_content_type(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'application/xhtml+xml'], '<html/>'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('application/xhtml+xml', $page->contentType);
    }

    public function test_success_allows_empty_body_on_204(): void
    {
        $client = $this->clientWithResponses([
            new Response(204, ['Content-Type' => 'text/html']),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('', $page->body);
        self::assertSame(204, $page->statusCode);
    }

    public function test_pins_dns_via_curl_resolve_option(): void
    {
        $mock = null;
        $client = $this->clientWithResponses(
            [new Response(200, ['Content-Type' => 'text/html'], 'ok')],
            $mock,
        );
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $fetcher->fetch($this->target());

        self::assertSame(
            ['example.com:443:203.0.113.10'],
            $mock->getLastOptions()['curl'][CURLOPT_RESOLVE],
        );
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failures_map_to_transport_error(\Throwable $failure): void
    {
        $client = $this->clientWithResponses([$failure]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::Transport, $e->error);
            self::assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function transportFailures(): array
    {
        $request = new Request('GET', 'https://example.com/article');

        return [
            'connect exception' => [new ConnectException('Connection timed out', $request)],
            'request exception' => [new RequestException('Network error', $request)],
        ];
    }

    #[DataProvider('redirectStatuses')]
    public function test_redirect_status_fails_closed(int $status): void
    {
        $client = $this->clientWithResponses([
            new Response($status, ['Location' => 'https://example.com/other']),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::Redirect, $e->error);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function redirectStatuses(): array
    {
        return [
            'status 301' => [301],
            'status 302' => [302],
            'status 307' => [307],
        ];
    }

    #[DataProvider('httpErrorStatuses')]
    public function test_non_success_status_fails_closed(int $status): void
    {
        $client = $this->clientWithResponses([
            new Response($status, ['Content-Type' => 'text/html'], 'error page'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::HttpStatus, $e->error);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function httpErrorStatuses(): array
    {
        return [
            'status 404' => [404],
            'status 500' => [500],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('disallowedContentTypes')]
    public function test_disallowed_content_type_fails_closed(array $headers): void
    {
        $client = $this->clientWithResponses([
            new Response(200, $headers, 'binary or other'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::ContentType, $e->error);
        }
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function disallowedContentTypes(): array
    {
        return [
            'pdf' => [['Content-Type' => 'application/pdf']],
            'json' => [['Content-Type' => 'application/json']],
            'missing header' => [[]],
        ];
    }

    public function test_content_length_over_max_fails_before_reading_body(): void
    {
        $client = $this->clientWithResponses([
            new Response(
                200,
                ['Content-Type' => 'text/html', 'Content-Length' => '1000'],
                'small body under the declared length',
            ),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 10), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::BodyTooLarge, $e->error);
        }
    }

    public function test_content_length_is_advisory_when_content_encoding_present(): void
    {
        $client = $this->clientWithResponses([
            new Response(
                200,
                [
                    'Content-Type' => 'text/html',
                    'Content-Length' => '1000',
                    'Content-Encoding' => 'gzip',
                ],
                'short',
            ),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 100), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('short', $page->body);
    }

    public function test_body_exceeding_limit_while_streaming_fails_closed(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'this body is too long for the cap'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 5), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::BodyTooLarge, $e->error);
        }
    }

    public function test_body_exactly_at_max_bytes_succeeds(): void
    {
        $body = str_repeat('a', 10);
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], $body),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 10), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame($body, $page->body);
        self::assertSame(10, strlen($page->body));
    }

    public function test_injected_client_logs_pin_skipped_warning(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, $logger);

        $fetcher->fetch($this->target());

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        ));
        self::assertNotEmpty($warnings);
        self::assertSame('example.com', $warnings[0]['context']['host']);
        self::assertSame('https://example.com/article', $warnings[0]['context']['requestUri']);
    }

    public function test_no_client_given_marks_client_as_internal(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy());

        $reflection = new \ReflectionProperty(HttpFetcher::class, 'clientIsInternal');
        self::assertTrue($reflection->getValue($fetcher));
    }

    public function test_injected_client_is_not_marked_internal(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client);

        $reflection = new \ReflectionProperty(HttpFetcher::class, 'clientIsInternal');
        self::assertFalse($reflection->getValue($fetcher));
    }

    public function test_constructs_with_no_arguments(): void
    {
        $fetcher = new HttpFetcher();
        self::assertInstanceOf(HttpFetcher::class, $fetcher);
    }

    public function test_default_client_attaches_debug_log_middleware_when_debug_enabled(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy(debug: true));

        self::assertTrue($this->defaultClientHasMiddleware($fetcher, 'debug_log'));
    }

    public function test_default_client_skips_debug_log_middleware_when_debug_disabled(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy(debug: false));

        self::assertFalse($this->defaultClientHasMiddleware($fetcher, 'debug_log'));
    }

    private function defaultClientHasMiddleware(HttpFetcher $fetcher, string $name): bool
    {
        $clientProperty = new \ReflectionProperty(HttpFetcher::class, 'client');
        /** @var Client $client */
        $client = $clientProperty->getValue($fetcher);

        /** @var HandlerStack<callable> $stack */
        $stack = $client->getConfig('handler');
        $stackProperty = new \ReflectionProperty(HandlerStack::class, 'stack');
        /** @var list<array{0: callable, 1: string}> $entries */
        $entries = $stackProperty->getValue($stack);

        foreach ($entries as [, $middlewareName]) {
            if ($middlewareName === $name) {
                return true;
            }
        }

        return false;
    }
}
