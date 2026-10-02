<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\Orchestrator\Orchestrator;
use Yumo\LogRead\Tests\UrlGuard\FakeSsrfUrlValidator;
use Yumo\LogRead\UrlGuard\SsrfUrlValidator;
use Yumo\LogRead\UrlGuard\UrlGuard;

/**
 * This helper wires the real pipeline stages around a mocked HTTP client and a fake SSRF validator.
 *
 * Orchestrator tests use it so a scenario can change one stage without opening a socket or doing a DNS lookup.
 */
final class PipelineHarness
{
    /**
     * This method builds an orchestrator. Omitted collaborators use the production stage classes with test doubles only at the network edges.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    public static function make(
        array $responses = [],
        ?ExtractPolicy $extractPolicy = null,
        ?HtmlSanitizer $htmlSanitizer = null,
        ?EncodingNormalizer $encodingNormalizer = null,
        ?LoggerInterface $logger = null,
        ?SsrfUrlValidator $validator = null,
    ): Orchestrator {
        $logger ??= new NullLogger();
        $extractPolicy ??= new ExtractPolicy();

        return new Orchestrator(
            new UrlGuard($validator ?? new FakeSsrfUrlValidator()),
            new HttpFetcher(new FetchPolicy(), self::client($responses), $logger),
            $encodingNormalizer ?? new EncodingNormalizer(),
            new ArticleExtractor($extractPolicy, $extractPolicy->debug ? $logger : null),
            $htmlSanitizer ?? new HtmlSanitizer(new PurifyPolicy(debug: true)),
            $logger,
        );
    }

    /**
     * This method returns a Guzzle client whose next responses are `$responses`, in order.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    private static function client(array $responses): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    /**
     * This method returns HTML whose article text is long enough for the default character threshold of 500.
     */
    public static function articleHtml(): string
    {
        $paragraph = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);

        return '<!DOCTYPE html><html><head><title>Story Title</title>'
            . '<meta property="og:description" content="A short excerpt">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body>'
            . '<article><h1>Story Title</h1><p>' . $paragraph . '</p>'
            . '<p><a href="/x">more</a></p></article>'
            . '</body></html>';
    }

    /**
     * This method returns the article fixture with one illegal UTF-8 sequence in a comment.
     *
     * The article text stays ASCII, so extraction can still succeed after a lossy conversion.
     */
    public static function articleHtmlWithInvalidUtf8(): string
    {
        return str_replace(
            '<title>',
            "<!-- \xC3\x28 --><title>",
            self::articleHtml(),
        );
    }

    public static function htmlResponse(string $body, string $contentType = 'text/html; charset=utf-8'): Response
    {
        return new Response(200, ['Content-Type' => $contentType], $body);
    }
}
