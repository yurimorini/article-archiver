<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

/**
 * Downloads one already-guarded URL with hard limits and returns a bounded byte payload, or throws.
 *
 * Performs a single pinned HTTP GET: the TCP connection is forced to the public IP addresses
 * that `UrlGuard` already checked (DNS pinning, via cURL's `CURLOPT_RESOLVE`), redirects are
 * refused rather than followed, and both the declared and the streamed body size are capped.
 * This class does not decode character encoding; `FetchedPage::$body` is opaque bytes.
 *
 * `$page = (new HttpFetcher())->fetch($guardResult->safe);`
 *
 * Unit tests inject a Guzzle client backed by `MockHandler` so no real HTTP happens:
 * `new HttpFetcher($policy, $clientWithMockHandler, $logger)`.
 */
final class HttpFetcher
{
    /** Timeouts, body size cap, and outgoing headers for this fetcher. */
    private FetchPolicy $policy;

    /** Guzzle-compatible client used to perform the GET request. */
    private ClientInterface $client;

    /** Destination for the DNS-pin-skipped warning and, when `FetchPolicy::$debug` is true, transfer summaries. */
    private LoggerInterface $logger;

    /**
     * Whether `$client` was built by `createDefaultClient()` rather than injected.
     *
     * The internal client always uses Guzzle's `CurlHandler` (`ext-curl` is a
     * hard Composer requirement), so DNS pinning is assumed to work there. Guzzle composes an
     * injected client's handler stack from closures that do not expose the terminal transport,
     * so an externally injected client's ability to honour `CURLOPT_RESOLVE` cannot be checked
     * reliably; every request made through one logs a pin-skipped warning instead of guessing.
     */
    private bool $clientIsInternal;

    /**
     * Creates a fetcher. When `$policy`, `$client`, or `$logger` are omitted, production defaults
     * are used: a `FetchPolicy` with default limits, an internal cURL-backed Guzzle client built
     * from that policy, and a no-op logger.
     */
    public function __construct(
        ?FetchPolicy $policy = null,
        ?ClientInterface $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->policy = $policy ?? new FetchPolicy();
        $this->logger = $logger ?? new NullLogger();
        $this->clientIsInternal = $client === null;
        $this->client = $client ?? $this->createDefaultClient($this->policy);
    }

    /**
     * @throws HttpFetcherException
     */
    public function fetch(SafeFetchTarget $target): FetchedPage
    {
        if (!$this->clientIsInternal) {
            $this->logger->warning(
                'DNS pin skipped: injected HTTP client is not the internal cURL client and cannot be assumed to honour CURLOPT_RESOLVE',
                ['requestUri' => $target->requestUri, 'host' => $target->host],
            );
        }

        $options = [
            'curl' => [
                CURLOPT_RESOLVE => $target->curlResolveEntries(),
            ],
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        try {
            $response = $this->client->request('GET', $target->requestUri, $options);
        } catch (\Throwable $e) {
            throw new HttpFetcherException(HttpFetcherError::Transport, '', $e);
        }

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');

        $this->assertSuccessfulStatus($statusCode);
        $this->assertAllowedContentType($contentType);
        $this->assertDeclaredLengthWithinLimit($response);

        $body = $this->readBodyWithinLimit($response->getBody());

        return new FetchedPage($body, $contentType, $statusCode, $target->requestUri);
    }

    /**
     * Rejects 3xx as Redirect and any other non-2xx as HttpStatus.
     */
    private function assertSuccessfulStatus(int $statusCode): void
    {
        if ($statusCode >= 300 && $statusCode < 400) {
            throw new HttpFetcherException(
                HttpFetcherError::Redirect,
                sprintf('HTTP redirect (status %d) is not followed', $statusCode),
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new HttpFetcherException(
                HttpFetcherError::HttpStatus,
                sprintf('HTTP response status %d is not successful', $statusCode),
            );
        }
    }

    /**
     * Rejects a missing or non-HTML Content-Type.
     */
    private function assertAllowedContentType(string $contentType): void
    {
        if ($contentType === '') {
            throw new HttpFetcherException(
                HttpFetcherError::ContentType,
                'Response has no Content-Type header',
            );
        }

        $mimeType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mimeType !== 'text/html' && $mimeType !== 'application/xhtml+xml') {
            throw new HttpFetcherException(
                HttpFetcherError::ContentType,
                sprintf('Response Content-Type "%s" is not an allowed HTML type', $contentType),
            );
        }
    }

    private function assertDeclaredLengthWithinLimit(ResponseInterface $response): void
    {
        $contentEncoding = $response->getHeaderLine('Content-Encoding');
        if ($contentEncoding !== '') {
            return;
        }

        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            return;
        }

        $declaredBytes = (int) $contentLength;
        if ($declaredBytes > $this->policy->maxBytes) {
            throw new HttpFetcherException(
                HttpFetcherError::BodyTooLarge,
                sprintf('Content-Length %d exceeds the %d byte limit', $declaredBytes, $this->policy->maxBytes),
            );
        }
    }

    private function readBodyWithinLimit(StreamInterface $stream): string
    {
        $chunkSize = 8192;
        $body = '';
        $bytesRead = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read($chunkSize);
            $bytesRead += strlen($chunk);
            if ($bytesRead > $this->policy->maxBytes) {
                throw new HttpFetcherException(
                    HttpFetcherError::BodyTooLarge,
                    sprintf('Response body exceeds the %d byte limit while streaming', $this->policy->maxBytes),
                );
            }
            $body .= $chunk;
        }

        return $body;
    }

    /**
     * Builds the production Guzzle client: an explicit cURL handler (so `CURLOPT_RESOLVE`
     * pinning is applied), fixed timeouts and headers from `$policy`, redirects disabled, and
     * HTTP-status exceptions disabled (status is checked explicitly in `fetch()` instead of
     * relying on Guzzle to throw for it).
     *
     * Guzzle's default stack wraps cURL with a stream-handler fallback. That fallback is
     * selected when the `stream` request option is true, and StreamHandler rejects `curl`
     * options. Pinning therefore requires a cURL-only stack; the body size cap is enforced
     * afterwards by reading the PSR-7 body in chunks, not by Guzzle's `stream` option.
     */
    private function createDefaultClient(FetchPolicy $policy): ClientInterface
    {
        $stack = HandlerStack::create(new CurlHandler());
        if ($policy->debug) {
            $stack->push(
                Middleware::log(
                    $this->logger,
                    new MessageFormatter(MessageFormatter::SHORT),
                    'debug',
                ),
                'debug_log',
            );
        }

        return new Client([
            'handler' => $stack,
            'timeout' => $policy->timeoutSeconds,
            'connect_timeout' => $policy->connectTimeoutSeconds,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $policy->userAgent,
                'Accept' => $policy->accept,
            ],
        ]);
    }
}
