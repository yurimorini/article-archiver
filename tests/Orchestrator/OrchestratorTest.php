<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorException;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizerException;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;
use Yumo\LogRead\Orchestrator\OrchestratorError;
use Yumo\LogRead\Orchestrator\OrchestratorException;
use Yumo\LogRead\Orchestrator\PipelineEmptyReason;
use Yumo\LogRead\Orchestrator\PipelineNoContent;
use Yumo\LogRead\Orchestrator\PipelineSuccess;
use Yumo\LogRead\Orchestrator\PipelineWarningCode;
use Yumo\LogRead\Tests\ArticleExtractor\RecordingLogger;
use Yumo\LogRead\Tests\EncodingNormalizer\FakeUtf8Converter;
use Yumo\LogRead\UrlGuard\UrlGuardException;

final class OrchestratorTest extends TestCase
{
    private const URL = 'https://example.com/article';

    public function test_happy_path_returns_non_empty_article_html(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse(PipelineHarness::articleHtml())],
            logger: $logger,
        );

        $result = $orchestrator->fetchArticle(self::URL);

        self::assertInstanceOf(PipelineSuccess::class, $result);
        self::assertFalse($result->isDegraded());
        self::assertSame([], $result->warnings);
        self::assertNotSame('', trim($result->document->html));
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $result->document->html);
        self::assertStringContainsString('href="https://example.com/x"', $result->document->html);
        self::assertSame('Story Title', $result->document->title->raw());
        self::assertNotNull($result->document->excerpt);
        self::assertSame('A short excerpt', $result->document->excerpt->raw());
        self::assertNotNull($result->document->siteName);
        self::assertSame('Example News', $result->document->siteName->raw());
        self::assertSame(self::URL, $result->document->sourceUrl);
        self::assertSame(
            ['URL accepted', 'Page fetched', 'Character encoding normalized', 'Article extracted', 'Article HTML sanitized'],
            $this->messagesAt($logger, 'info'),
        );
        self::assertNotSame([], $this->messagesAt($logger, 'warning'));
        self::assertSame([], $this->messagesAt($logger, 'error'));
    }

    public function test_degraded_encoding_keeps_the_article_and_adds_a_warning(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse(PipelineHarness::articleHtmlWithInvalidUtf8())],
            logger: $logger,
        );

        $result = $orchestrator->fetchArticle(self::URL);

        self::assertInstanceOf(PipelineSuccess::class, $result);
        self::assertTrue($result->isDegraded());
        self::assertCount(1, $result->warnings);
        self::assertSame(PipelineWarningCode::EncodingDegraded, $result->warnings[0]->code);
        self::assertSame(EncodingError::Conversion->defaultMessage(), $result->warnings[0]->message);
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $result->document->html);
        self::assertSame(
            [EncodingError::Conversion->defaultMessage()],
            $this->messagesAt($logger, 'warning', EncodingError::Conversion->defaultMessage()),
        );
    }

    public function test_blank_body_is_extract_no_content(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse(" \n\t")],
            logger: $logger,
        );

        $result = $orchestrator->fetchArticle(self::URL);

        self::assertInstanceOf(PipelineNoContent::class, $result);
        self::assertSame(PipelineEmptyReason::ExtractNoContent, $result->reason);
        self::assertSame(self::URL, $result->sourceUrl);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame([], $result->warnings);
        self::assertSame(['Article extraction found no content'], $this->messagesAt($logger, 'notice'));
        self::assertSame([], $this->messagesAt($logger, 'info', 'Article HTML sanitized'));
    }

    public function test_extract_no_content_keeps_metadata_and_an_encoding_warning(): void
    {
        $html = '<html><head><!-- ' . "\xC3\x28" . ' -->'
            . '<meta property="og:title" content="OG Title">'
            . '<meta name="description" content="Just a blurb">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body></body></html>';
        $orchestrator = PipelineHarness::make([
            PipelineHarness::htmlResponse($html),
        ]);

        $result = $orchestrator->fetchArticle(self::URL);

        self::assertInstanceOf(PipelineNoContent::class, $result);
        self::assertSame(PipelineEmptyReason::ExtractNoContent, $result->reason);
        self::assertSame('OG Title', $result->title->raw());
        self::assertNotNull($result->excerpt);
        self::assertSame('Just a blurb', $result->excerpt->raw());
        self::assertNotNull($result->siteName);
        self::assertSame('Example News', $result->siteName->raw());
        self::assertSame(self::URL, $result->sourceUrl);
        self::assertCount(1, $result->warnings);
        self::assertSame(PipelineWarningCode::EncodingDegraded, $result->warnings[0]->code);
        self::assertSame(EncodingError::Conversion->defaultMessage(), $result->warnings[0]->message);
    }

    public function test_empty_purified_html_is_sanitized_empty(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse('<html><body><article><p></p></article></body></html>')],
            extractPolicy: new ExtractPolicy(charThreshold: 0),
            logger: $logger,
        );

        $result = $orchestrator->fetchArticle(self::URL);

        self::assertInstanceOf(PipelineNoContent::class, $result);
        self::assertSame(PipelineEmptyReason::SanitizedEmpty, $result->reason);
        self::assertSame(self::URL, $result->sourceUrl);
        self::assertSame(['Sanitized article HTML is empty'], $this->messagesAt($logger, 'notice'));
        self::assertSame([], $this->messagesAt($logger, 'info', 'Article HTML sanitized'));
    }

    public function test_url_guard_failure_is_wrapped(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make(logger: $logger);

        try {
            $orchestrator->fetchArticle('ftp://example.com/file');
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::UrlGuard, $exception->error);
            self::assertSame('URL guard rejected the input URL', $exception->getMessage());
            self::assertInstanceOf(UrlGuardException::class, $exception->getPrevious());
            self::assertSame(['URL guard rejected the input URL'], $this->messagesAt($logger, 'error'));
        }
    }

    public function test_fetch_failure_is_wrapped_and_redirect_is_not_followed(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = PipelineHarness::make([
            new Response(302, ['Location' => 'https://example.com/other', 'Content-Type' => 'text/html'], ''),
        ], logger: $logger);

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Fetch, $exception->error);
            self::assertSame('HTTP fetch failed', $exception->getMessage());
            $previous = $exception->getPrevious();
            self::assertInstanceOf(HttpFetcherException::class, $previous);
            self::assertSame(HttpFetcherError::Redirect, $previous->error);
            self::assertSame(['HTTP fetch failed'], $this->messagesAt($logger, 'error'));
        }
    }

    public function test_http_status_failure_is_wrapped_as_fetch(): void
    {
        $orchestrator = PipelineHarness::make([
            new Response(404, ['Content-Type' => 'text/html'], 'missing'),
        ]);

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Fetch, $exception->error);
            $previous = $exception->getPrevious();
            self::assertInstanceOf(HttpFetcherException::class, $previous);
            self::assertSame(HttpFetcherError::HttpStatus, $previous->error);
        }
    }

    public function test_encoding_failure_is_wrapped(): void
    {
        $normalizer = new EncodingNormalizer(new FakeUtf8Converter(
            new \RuntimeException('strict failed'),
            new \RuntimeException('lossy failed'),
        ));
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse('<html>ok</html>', 'text/html; charset=ISO-8859-1')],
            encodingNormalizer: $normalizer,
        );

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Encoding, $exception->error);
            self::assertSame('Character encoding normalization failed', $exception->getMessage());
            self::assertInstanceOf(EncodingNormalizerException::class, $exception->getPrevious());
        }
    }

    public function test_extract_failure_is_wrapped(): void
    {
        $html = '<html><body>' . str_repeat('<span>x</span>', 40) . '</body></html>';
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse($html)],
            extractPolicy: new ExtractPolicy(maxElemsToParse: 10),
        );

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Extract, $exception->error);
            self::assertSame('Article extraction failed', $exception->getMessage());
            self::assertInstanceOf(ArticleExtractorException::class, $exception->getPrevious());
        }
    }

    public function test_sanitize_failure_is_wrapped(): void
    {
        $cacheFile = tempnam(sys_get_temp_dir(), 'lr-cache-');
        if ($cacheFile === false) {
            self::fail('Could not create a temp cache file');
        }

        try {
            $orchestrator = PipelineHarness::make(
                [PipelineHarness::htmlResponse(PipelineHarness::articleHtml())],
                htmlSanitizer: new HtmlSanitizer(new PurifyPolicy(), $cacheFile),
            );

            try {
                $orchestrator->fetchArticle(self::URL);
                self::fail('Expected OrchestratorException');
            } catch (OrchestratorException $exception) {
                self::assertSame(OrchestratorError::Sanitize, $exception->error);
                self::assertSame('HTML sanitization failed', $exception->getMessage());
                self::assertInstanceOf(HtmlSanitizerException::class, $exception->getPrevious());
            }
        } finally {
            unlink($cacheFile);
        }
    }

    public function test_a_logger_throw_on_the_encoding_hop_is_wrapped(): void
    {
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse(PipelineHarness::articleHtml())],
            logger: new class () extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if ((string) $message === 'Character encoding normalized') {
                        throw new \RuntimeException('log failed');
                    }
                }
            },
        );

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Unexpected, $exception->error);
            self::assertSame('Article pipeline failed unexpectedly', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame('log failed', $exception->getPrevious()->getMessage());
        }
    }

    public function test_unexpected_throwable_is_wrapped(): void
    {
        $orchestrator = PipelineHarness::make(
            [PipelineHarness::htmlResponse(PipelineHarness::articleHtml())],
            logger: new class () extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    throw new \RuntimeException('log failed');
                }
            },
        );

        try {
            $orchestrator->fetchArticle(self::URL);
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $exception) {
            self::assertSame(OrchestratorError::Unexpected, $exception->error);
            self::assertSame('Article pipeline failed unexpectedly', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame('log failed', $exception->getPrevious()->getMessage());
        }
    }

    /**
     * This method returns recorded messages at `$level`, optionally only those equal to `$message`.
     *
     * @return list<string>
     */
    private function messagesAt(RecordingLogger $logger, string $level, ?string $message = null): array
    {
        $messages = [];
        foreach ($logger->records as [$recordedLevel, $recordedMessage]) {
            if ($recordedLevel !== $level) {
                continue;
            }
            if ($message !== null && $recordedMessage !== $message) {
                continue;
            }
            $messages[] = $recordedMessage;
        }

        return $messages;
    }
}
