<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Yumo\ArtcleReader\ArticleExtractor\ArticleExtractor;
use Yumo\ArtcleReader\ArticleExtractor\ExtractResult;
use Yumo\ArtcleReader\ArticleExtractor\PlainText;
use Yumo\ArtcleReader\EncodingNormalizer\EncodingNormalizer;
use Yumo\ArtcleReader\HtmlSanitizer\HtmlSanitizer;
use Yumo\ArtcleReader\HtmlSanitizer\PurifyPolicy;
use Yumo\ArtcleReader\HtmlSanitizer\SafeDocument;
use Yumo\ArtcleReader\HttpFetcher\FetchPolicy;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\GuardedFetch;
use Yumo\ArtcleReader\Orchestrator\Orchestrator;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\UrlGuard\SsrfUrlValidator;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveFailureMapper;
use Yumo\Eleanor\Archive\ArticleReaderAdapter;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Proves the adapter turns a library outcome into an article or an archive failure.
 */
final class ArticleReaderAdapterTest extends TestCase
{
    private const string URL = 'https://example.com/article';

    /**
     * @return iterable<string, array{OrchestratorException, ArchiveFailure, ?int}>
     */
    public static function orchestratorFailures(): iterable
    {
        yield 'url guard syntax' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Syntax)),
            ArchiveFailure::UrlSyntax,
            null,
        ];
        yield 'url guard policy' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Policy)),
            ArchiveFailure::UrlPolicy,
            null,
        ];
        yield 'url guard rejected' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Rejected)),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'url guard without a previous' => [
            new OrchestratorException(OrchestratorError::UrlGuard),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'url guard with another previous' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new \LogicException('x')),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'fetch transport' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::Transport)),
            ArchiveFailure::Transport,
            null,
        ];
        yield 'fetch http status' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::HttpStatus, statusCode: 503)),
            ArchiveFailure::UpstreamStatus,
            503,
        ];
        yield 'fetch http status without a number' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::HttpStatus)),
            ArchiveFailure::UpstreamStatus,
            null,
        ];
        yield 'fetch redirect' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::Redirect, statusCode: 301)),
            ArchiveFailure::Redirect,
            null,
        ];
        yield 'fetch redirect limit' => [
            self::fetchFailure(new HttpFetcherException(
                HttpFetcherError::RedirectLimit,
                'HTTP redirect hop cap is exhausted (status 302)',
                statusCode: 302,
            )),
            ArchiveFailure::Unexpected,
            null,
        ];
        yield 'fetch content type' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::ContentType)),
            ArchiveFailure::ContentType,
            null,
        ];
        yield 'fetch body too large' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::BodyTooLarge)),
            ArchiveFailure::BodyTooLarge,
            null,
        ];
        yield 'fetch without a previous' => [
            new OrchestratorException(OrchestratorError::Fetch),
            ArchiveFailure::Unexpected,
            null,
        ];
        yield 'fetch with another previous' => [
            self::fetchFailure(new \RuntimeException('x')),
            ArchiveFailure::Unexpected,
            null,
        ];
        yield 'encoding' => [new OrchestratorException(OrchestratorError::Encoding), ArchiveFailure::Encoding, null];
        yield 'extract' => [new OrchestratorException(OrchestratorError::Extract), ArchiveFailure::Extract, null];
        yield 'sanitize' => [new OrchestratorException(OrchestratorError::Sanitize), ArchiveFailure::Sanitize, null];
        yield 'unexpected' => [
            new OrchestratorException(OrchestratorError::Unexpected),
            ArchiveFailure::Unexpected,
            null,
        ];
    }

    #[DataProvider('orchestratorFailures')]
    public function test_orchestrator_failures_map_by_stage_and_reason(
        OrchestratorException $exception,
        ArchiveFailure $expected,
        ?int $upstreamStatus,
    ): void {
        $archiveException = new ArchiveFailureMapper()->fromOrchestrator($exception);

        self::assertSame($expected, $archiveException->failure);
        self::assertSame($upstreamStatus, $archiveException->upstreamStatus);
        self::assertSame($exception, $archiveException->getPrevious());
    }

    public function test_empty_pages_have_no_cause(): void
    {
        $mapper = new ArchiveFailureMapper();

        $noArticle = $mapper->fromEmpty(PipelineNoContent::fromExtract(ExtractResult::noContent(self::URL)));
        $sanitizedEmpty = $mapper->fromEmpty(PipelineNoContent::fromSanitizedEmpty(
            new SafeDocument('', PlainText::fromUntrusted(''), null, null, self::URL),
        ));

        self::assertSame(ArchiveFailure::ExtractNoContent, $noArticle->failure);
        self::assertNull($noArticle->getPrevious());
        self::assertSame(ArchiveFailure::SanitizedEmpty, $sanitizedEmpty->failure);
        self::assertNull($sanitizedEmpty->getPrevious());
    }

    public function test_success_drops_warnings(): void
    {
        $adapter = new ArticleReaderAdapter($this->orchestrator([
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->articleHtml(true)),
        ]));

        $article = $adapter->fetch(new UrlPointer(self::URL));

        self::assertSame('Story Title', $article->title);
        self::assertSame('A short excerpt', $article->excerpt);
        self::assertSame('Example News', $article->siteName);
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $article->html);
    }

    public function test_a_library_failure_keeps_its_cause(): void
    {
        $adapter = new ArticleReaderAdapter($this->orchestrator([
            new Response(503, ['Content-Type' => 'text/html'], 'no'),
        ]));

        try {
            $adapter->fetch(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::UpstreamStatus, $exception->failure);
            self::assertSame(503, $exception->upstreamStatus);
            self::assertInstanceOf(OrchestratorException::class, $exception->getPrevious());
        }
    }

    private static function fetchFailure(\Throwable $previous): OrchestratorException
    {
        return new OrchestratorException(OrchestratorError::Fetch, '', $previous);
    }

    /**
     * @param list<Response> $responses
     */
    private function orchestrator(array $responses): Orchestrator
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);

        $urlGuard = new UrlGuard(new FixedSsrfUrlValidator());
        $httpFetcher = new HttpFetcher(new FetchPolicy(), $client);
        $logger = new NullLogger();

        return new Orchestrator(
            new GuardedFetch($urlGuard, $httpFetcher, $logger),
            new EncodingNormalizer(),
            new ArticleExtractor(),
            new HtmlSanitizer(new PurifyPolicy(debug: true)),
        );
    }

    private function articleHtml(bool $invalidUtf8): string
    {
        $paragraph = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);
        $comment = $invalidUtf8 ? "<!-- \xC3\x28 -->" : '';

        return '<!DOCTYPE html><html><head>' . $comment . '<title>Story Title</title>'
            . '<meta property="og:description" content="A short excerpt">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body><article><h1>Story Title</h1><p>' . $paragraph . '</p>'
            . '<p><a href="/x">more</a></p></article></body></html>';
    }
}

/**
 * Returns one public address so a fetch test does not look up DNS.
 */
final class FixedSsrfUrlValidator implements SsrfUrlValidator
{
    public function validate(string $url): array
    {
        return ['203.0.113.10'];
    }
}
