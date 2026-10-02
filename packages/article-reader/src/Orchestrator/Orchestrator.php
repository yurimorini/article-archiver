<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorException;
use Yumo\LogRead\ArticleExtractor\ExtractResult;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizerException;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;
use Yumo\LogRead\HttpFetcher\FetchedPage;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;
use Yumo\LogRead\UrlGuard\UrlGuard;
use Yumo\LogRead\UrlGuard\UrlGuardException;

/**
 * This class fetches one URL and returns either a purified article or a typed empty outcome.
 *
 * It runs the URL check, the HTTP download, character-encoding conversion, article extraction, and HTML purification, in that order.
 * A lossy encoding conversion continues and is reported as a warning. A page with no article, and a page whose HTML is empty after purification, are empty outcomes rather than exceptions.
 * Anything else is thrown as `OrchestratorException`. Redirects are not followed and a failed download is not retried.
 *
 * `$result = $orchestrator->fetchArticle($url);`
 *
 * Everyday callers build the instance with `OrchestratorFactory::create()`. Pass collaborators to this constructor in tests.
 */
final class Orchestrator
{
    /**
     * This constructor stores the collaborators. When `$logger` is omitted, log records are discarded.
     */
    public function __construct(
        /** This value checks the URL before any request is made. */
        private UrlGuard $urlGuard,
        /** This value downloads the guarded URL once. */
        private HttpFetcher $httpFetcher,
        /** This value turns the response bytes into UTF-8 HTML. */
        private EncodingNormalizer $encodingNormalizer,
        /** This value extracts the article from the UTF-8 page. */
        private ArticleExtractor $articleExtractor,
        /** This value purifies the extracted article HTML. */
        private HtmlSanitizer $htmlSanitizer,
        /** This value receives one log record per hop, plus soft warnings and hard failures. */
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * This method fetches `$url` and returns a purified article or an empty outcome.
     *
     * @throws OrchestratorException When the URL is rejected or a stage aborts
     */
    public function fetchArticle(string $url): PipelineSuccess|PipelineNoContent
    {
        $target = $this->guardUrl($url);
        $page = $this->fetchPage($target);
        [$html, $warnings] = $this->normalizeEncoding($page);

        $extract = $this->extractArticle($html);
        if ($extract->isNoContent()) {
            return PipelineNoContent::fromExtract($extract, $warnings);
        }

        $document = $extract->document ?? throw new OrchestratorException(OrchestratorError::Unexpected);
        $safe = $this->purifyArticle($document);
        if (trim($safe->html) === '') {
            return PipelineNoContent::fromSanitizedEmpty($safe, $warnings);
        }

        return new PipelineSuccess($safe, $warnings);
    }

    /**
     * This method checks `$url` and returns the fetch target, or throws a pipeline exception.
     *
     * @throws OrchestratorException
     */
    private function guardUrl(string $url): SafeFetchTarget
    {
        try {
            $result = $this->urlGuard->guard($url);
            $this->logger->info('URL accepted', ['url' => $url]);

            return $result->safe;
        } catch (UrlGuardException $exception) {
            throw $this->failure(OrchestratorError::UrlGuard, $exception);
        } catch (\Throwable $exception) {
            throw $this->failure(OrchestratorError::Unexpected, $exception);
        }
    }

    /**
     * This method downloads `$target` once. A redirect or a transport error becomes a pipeline exception.
     *
     * @throws OrchestratorException
     */
    private function fetchPage(SafeFetchTarget $target): FetchedPage
    {
        try {
            $page = $this->httpFetcher->fetch($target);
            $this->logger->info('Page fetched', ['requestUri' => $target->requestUri]);

            return $page;
        } catch (HttpFetcherException $exception) {
            throw $this->failure(OrchestratorError::Fetch, $exception);
        } catch (\Throwable $exception) {
            throw $this->failure(OrchestratorError::Unexpected, $exception);
        }
    }

    /**
     * This method converts `$page` to UTF-8. A lossy conversion is a warning and the HTML is still returned.
     *
     * @return array{Utf8Html, list<PipelineWarning>}
     *
     * @throws OrchestratorException
     */
    private function normalizeEncoding(FetchedPage $page): array
    {
        try {
            $outcome = $this->encodingNormalizer->normalize($page);
            if (!$outcome->isDegraded()) {
                $this->logger->info('Character encoding normalized', ['requestUri' => $page->requestUri]);

                return [$outcome->html, []];
            }

            $warning = new PipelineWarning(
                PipelineWarningCode::EncodingDegraded,
                ($outcome->warning ?? EncodingError::Conversion)->defaultMessage(),
                $outcome->previous,
            );
            $this->logger->warning($warning->message, ['requestUri' => $page->requestUri, 'exception' => $outcome->previous]);

            return [$outcome->html, [$warning]];
        } catch (EncodingNormalizerException $exception) {
            throw $this->failure(OrchestratorError::Encoding, $exception);
        } catch (\Throwable $exception) {
            throw $this->failure(OrchestratorError::Unexpected, $exception);
        }
    }

    /**
     * This method extracts an article from `$html`. Finding no article is logged and returned, not thrown.
     *
     * @throws OrchestratorException
     */
    private function extractArticle(Utf8Html $html): ExtractResult
    {
        try {
            $extract = $this->articleExtractor->extract($html);
            if ($extract->isNoContent()) {
                $this->logger->notice('Article extraction found no content', ['sourceUrl' => $html->sourceUrl]);
            } else {
                $this->logger->info('Article extracted', ['sourceUrl' => $html->sourceUrl]);
            }

            return $extract;
        } catch (ArticleExtractorException $exception) {
            throw $this->failure(OrchestratorError::Extract, $exception);
        } catch (\Throwable $exception) {
            throw $this->failure(OrchestratorError::Unexpected, $exception);
        }
    }

    /**
     * This method purifies `$document`. Empty HTML is logged here and mapped by the caller.
     *
     * @throws OrchestratorException
     */
    private function purifyArticle(ReadableDocument $document): SafeDocument
    {
        try {
            $safe = $this->htmlSanitizer->purify($document);
            if (trim($safe->html) === '') {
                $this->logger->notice('Sanitized article HTML is empty', ['sourceUrl' => $document->sourceUrl]);
            } else {
                $this->logger->info('Article HTML sanitized', ['sourceUrl' => $document->sourceUrl]);
            }

            return $safe;
        } catch (HtmlSanitizerException $exception) {
            throw $this->failure(OrchestratorError::Sanitize, $exception);
        } catch (\Throwable $exception) {
            throw $this->failure(OrchestratorError::Unexpected, $exception);
        }
    }

    /**
     * This method logs `$error` and returns the pipeline exception the caller throws.
     *
     * A logger that itself throws is ignored so the original stage failure is the one that escapes.
     */
    private function failure(OrchestratorError $error, \Throwable $previous): OrchestratorException
    {
        $exception = new OrchestratorException($error, '', $previous);
        try {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
        } catch (\Throwable) {
        }

        return $exception;
    }
}
