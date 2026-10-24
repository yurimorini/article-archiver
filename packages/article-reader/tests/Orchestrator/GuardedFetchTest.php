<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Tests\Orchestrator;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\ArtcleReader\HttpFetcher\FetchPolicy;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\GuardedFetch;
use Yumo\ArtcleReader\Tests\ArticleExtractor\RecordingLogger;
use Yumo\ArtcleReader\Tests\UrlGuard\FakeSsrfUrlValidator;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;

final class GuardedFetchTest extends TestCase
{
    public function test_returns_the_caller_string_and_an_empty_history(): void
    {
        $logger = new RecordingLogger();
        $mock = new MockHandler([$this->htmlResponse('<html>ok</html>')]);
        $fetch = $this->guardedFetch($logger, $mock);
        $entryUrl = '  HTTPS://Example.com/article  ';

        $page = $fetch->fetch($entryUrl);

        self::assertSame($entryUrl, $page->originalUrl);
        self::assertSame([], $page->hopHistory);
        self::assertSame('https://example.com/article', $page->page->requestUri);
        self::assertSame(['URL accepted', 'Page fetched'], $this->infoMessages($logger));
    }

    public function test_a_cap_of_one_follows_one_hop(): void
    {
        $logger = new RecordingLogger();
        $mock = new MockHandler([
            new Response(302, ['Location' => '/next']),
            $this->htmlResponse('<html>next</html>'),
        ]);
        $fetch = $this->guardedFetch($logger, $mock, new FetchPolicy(maxRedirectHops: 1));
        $entryUrl = '  HTTPS://Example.com/article  ';

        $page = $fetch->fetch($entryUrl);

        self::assertSame($entryUrl, $page->originalUrl);
        self::assertSame(['https://example.com/article'], $page->hopHistory);
        self::assertSame('https://example.com/next', $page->page->requestUri);
        self::assertSame(['URL accepted', 'URL accepted', 'Page fetched'], $this->infoMessages($logger));
    }

    public function test_cap_zero_refuses_a_followable_hop(): void
    {
        $logger = new RecordingLogger();
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://example.com/next']),
            $this->htmlResponse('<html>next</html>'),
        ]);
        $fetch = $this->guardedFetch($logger, $mock, new FetchPolicy(maxRedirectHops: 0));

        try {
            $fetch->fetch('https://example.com/article');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame(HttpFetcherError::Redirect, $exception->error);
            self::assertSame(302, $exception->statusCode);
            self::assertSame('HTTP redirect (status 302) is not followed', $exception->getMessage());
            self::assertSame(1, $mock->count());
            self::assertSame(['URL accepted'], $this->infoMessages($logger));
        }
    }

    public function test_a_cap_of_five_lands_on_the_last_hop(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/h1']),
            new Response(302, ['Location' => '/h2']),
            new Response(302, ['Location' => '/h3']),
            new Response(302, ['Location' => '/h4']),
            new Response(302, ['Location' => '/h5']),
            $this->htmlResponse('<html>last</html>'),
        ]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: 5));

        $page = $fetch->fetch('https://example.com/start');

        self::assertSame(
            [
                'https://example.com/start',
                'https://example.com/h1',
                'https://example.com/h2',
                'https://example.com/h3',
                'https://example.com/h4',
            ],
            $page->hopHistory,
        );
        self::assertSame('https://example.com/h5', $page->page->requestUri);
    }

    public function test_a_hop_past_the_cap_is_redirect_limit(): void
    {
        $logger = new RecordingLogger();
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://example.com/b']),
            new Response(302, ['Location' => 'https://example.com/c']),
            $this->htmlResponse('<html>c</html>'),
        ]);
        $fetch = $this->guardedFetch($logger, $mock, new FetchPolicy(maxRedirectHops: 1));

        try {
            $fetch->fetch('https://example.com/a');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame(HttpFetcherError::RedirectLimit, $exception->error);
            self::assertSame(302, $exception->statusCode);
            self::assertSame('HTTP redirect hop cap is exhausted (status 302)', $exception->getMessage());
            self::assertSame(1, $mock->count());
            self::assertSame(['URL accepted', 'URL accepted'], $this->infoMessages($logger));
        }
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface> $redirects Responses queued before the final 200
     */
    #[DataProvider('nonHopCases')]
    public function test_a_non_hop_stays_redirect(int $cap, array $redirects, int $status): void
    {
        $mock = new MockHandler([...$redirects, $this->htmlResponse('<html>next</html>')]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: $cap));

        try {
            $fetch->fetch('https://example.com/a');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame(HttpFetcherError::Redirect, $exception->error);
            self::assertSame($status, $exception->statusCode);
            self::assertSame(1, $mock->count());
        }
    }

    /**
     * @return iterable<string, array{int, list<Response>, int}>
     */
    public static function nonHopCases(): iterable
    {
        $first = new Response(302, ['Location' => 'https://example.com/b']);

        yield 'first 304 with Location' => [5, [new Response(304, ['Location' => 'https://example.com/next'])], 304];
        yield 'first 300 with Location' => [5, [new Response(300, ['Location' => 'https://example.com/next'])], 300];
        yield 'first 302 without Location' => [5, [new Response(302)], 302];
        yield 'first 302 with blank Location' => [5, [new Response(302, ['Location' => '   '])], 302];
        yield 'second 304 with Location' => [1, [$first, new Response(304, ['Location' => 'https://example.com/c'])], 304];
        yield 'second 300 with Location' => [1, [$first, new Response(300, ['Location' => 'https://example.com/c'])], 300];
        yield 'second 301 without Location' => [1, [$first, new Response(301)], 301];
        yield 'second 302 with blank Location' => [1, [$first, new Response(302, ['Location' => '   '])], 302];
    }

    #[DataProvider('relativeLocationCases')]
    public function test_a_relative_location_is_resolved_before_the_guard(string $base, string $location, string $landed): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => $location]),
            $this->htmlResponse('<html>next</html>'),
        ]);
        $validator = new FakeSsrfUrlValidator();
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: 1), $validator);

        $page = $fetch->fetch($base);

        self::assertSame([$base], $page->hopHistory);
        self::assertSame($landed, $page->page->requestUri);
        self::assertSame(2, $validator->callCount);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relativeLocationCases(): iterable
    {
        yield 'absolute path' => ['https://example.com/article', '/next', 'https://example.com/next'];
        yield 'relative path' => ['https://example.com/dir/article', 'next', 'https://example.com/dir/next'];
        yield 'query only' => ['https://example.com/a', '?x=1', 'https://example.com/a?x=1'];
        yield 'network path' => ['https://example.com/a', '//other.example/x', 'https://other.example/x'];
        yield 'scheme change' => ['https://example.com/a', 'http://example.com/next', 'http://example.com/next'];
        yield 'port change' => ['https://example.com/a', 'https://example.com:8443/next', 'https://example.com:8443/next'];
    }

    public function test_a_location_does_not_inherit_the_previous_fragment(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/b']),
            $this->htmlResponse('<html>b</html>'),
        ]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: 1));

        $page = $fetch->fetch('https://example.com/a#frag');

        self::assertSame(['https://example.com/a#frag'], $page->hopHistory);
        self::assertSame('https://example.com/b', $page->page->requestUri);
    }

    public function test_two_location_lines_use_the_first(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => ['http://user:pass@example.com/no', 'https://example.com/yes']]),
            $this->htmlResponse('<html>yes</html>'),
        ]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: 1));

        try {
            $fetch->fetch('https://example.com/a');
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException) {
            self::assertSame(1, $mock->count());
        }
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface|\Throwable $second
     */
    #[DataProvider('laterHopFailureCases')]
    public function test_a_later_hop_failure_does_not_return_the_first_page(
        \Psr\Http\Message\ResponseInterface|\Throwable $second,
        HttpFetcherError $error,
    ): void {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://example.com/next']),
            $second,
        ]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxBytes: 10, maxRedirectHops: 1));

        try {
            $fetch->fetch('https://example.com/article');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame($error, $exception->error);
        }
    }

    /**
     * @return iterable<string, array{\Psr\Http\Message\ResponseInterface|\Throwable, HttpFetcherError}>
     */
    public static function laterHopFailureCases(): iterable
    {
        yield 'status' => [
            new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], '<html>no</html>'),
            HttpFetcherError::HttpStatus,
        ];
        yield 'transport' => [
            new ConnectException('Connection timed out', new Request('GET', 'https://example.com/next')),
            HttpFetcherError::Transport,
        ];
        yield 'content type' => [
            new Response(200, ['Content-Type' => 'text/plain'], 'plain'),
            HttpFetcherError::ContentType,
        ];
        yield 'body too large' => [
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>this body is longer than ten bytes</html>'),
            HttpFetcherError::BodyTooLarge,
        ];
    }

    public function test_a_redirect_loop_stops_at_the_cap(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://example.com/b']),
            new Response(302, ['Location' => 'https://example.com/a']),
            new Response(302, ['Location' => 'https://example.com/b']),
            $this->htmlResponse('<html>never</html>'),
        ]);
        $fetch = $this->guardedFetch(new RecordingLogger(), $mock, new FetchPolicy(maxRedirectHops: 2));

        try {
            $fetch->fetch('https://example.com/a');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame(HttpFetcherError::RedirectLimit, $exception->error);
            self::assertSame(1, $mock->count());
        }
    }

    /**
     * This method builds a guarded fetch around an address check and a queued HTTP client.
     *
     * Redirects stay off on the client, matching the pipeline harness. `$policy` configures the fetcher, and its hop cap is also passed to `GuardedFetch`. A test that needs the guard call count or the unused responses passes its own `$validator` and keeps `$mock`.
     */
    private function guardedFetch(
        RecordingLogger $logger,
        MockHandler $mock,
        ?FetchPolicy $policy = null,
        ?FakeSsrfUrlValidator $validator = null,
    ): GuardedFetch {
        $policy ??= new FetchPolicy();

        return new GuardedFetch(
            new UrlGuard($validator ?? new FakeSsrfUrlValidator()),
            new HttpFetcher($policy, $this->client($mock), $logger),
            $logger,
            $policy->maxRedirectHops,
        );
    }

    /**
     * This method returns a Guzzle client that answers from `$mock`.
     */
    private function client(MockHandler $mock): Client
    {
        return new Client([
            'handler' => HandlerStack::create($mock),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    /**
     * This method returns a 200 HTML response with `$body`.
     */
    private function htmlResponse(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /**
     * This method returns recorded info messages in order.
     *
     * @return list<string>
     */
    private function infoMessages(RecordingLogger $logger): array
    {
        $messages = [];
        foreach ($logger->records as [$level, $message]) {
            if ($level !== 'info') {
                continue;
            }
            $messages[] = $message;
        }

        return $messages;
    }
}
