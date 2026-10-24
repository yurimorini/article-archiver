<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Tests\Orchestrator;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Yumo\ArtcleReader\HttpFetcher\FetchPolicy;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\GuardedFetch;
use Yumo\ArtcleReader\Tests\ArticleExtractor\RecordingLogger;
use Yumo\ArtcleReader\Tests\UrlGuard\FakeSsrfUrlValidator;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;

final class GuardedFetchTest extends TestCase
{
    public function test_returns_the_caller_string_and_an_empty_history(): void
    {
        $logger = new RecordingLogger();
        $fetch = $this->guardedFetch($logger, [
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>ok</html>'),
        ]);
        $entryUrl = '  HTTPS://Example.com/article  ';

        $page = $fetch->fetch($entryUrl);

        self::assertSame($entryUrl, $page->originalUrl);
        self::assertSame([], $page->hopHistory);
        self::assertSame('https://example.com/article', $page->page->requestUri);
        self::assertSame(['URL accepted', 'Page fetched'], $this->infoMessages($logger));
    }

    public function test_a_positive_cap_still_does_not_follow(): void
    {
        $logger = new RecordingLogger();
        $fetch = $this->guardedFetch(
            $logger,
            [
                new Response(302, ['Location' => 'https://example.com/next']),
                new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>next</html>'),
            ],
            new FetchPolicy(maxRedirectHops: 5),
        );

        try {
            $fetch->fetch('https://example.com/article');
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $exception) {
            self::assertSame(HttpFetcherError::Redirect, $exception->error);
            self::assertSame(['URL accepted'], $this->infoMessages($logger));
        }
    }

    /**
     * This method builds a guarded fetch around a fake address check and a queued HTTP client.
     *
     * Redirects stay off on the client, matching the pipeline harness. `$policy` is the fetcher's policy and is not passed into `GuardedFetch`.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    private function guardedFetch(RecordingLogger $logger, array $responses, ?FetchPolicy $policy = null): GuardedFetch
    {
        return new GuardedFetch(
            new UrlGuard(new FakeSsrfUrlValidator()),
            new HttpFetcher($policy ?? new FetchPolicy(), $this->client($responses), $logger),
            $logger,
        );
    }

    /**
     * This method returns a Guzzle client whose next responses are `$responses`, in order.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    private function client(array $responses): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
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
