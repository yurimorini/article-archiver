<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Orchestrator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;

/**
 * This class checks one caller URL and downloads that page once.
 *
 * The caller string is stored unchanged. A redirect response is not followed.
 *
 * `$guarded = (new GuardedFetch($urlGuard, $httpFetcher))->fetch($entryUrl);`
 */
final class GuardedFetch
{
    /**
     * This constructor stores the guard, the downloader, and the logger.
     *
     * When `$logger` is omitted, log records are discarded.
     */
    public function __construct(
        /** This value checks the URL before any request is made. */
        private UrlGuard $urlGuard,
        /** This value downloads the guarded URL once. */
        private HttpFetcher $httpFetcher,
        /** This value receives an info record after the URL is accepted and after the page is downloaded. */
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * This method checks `$entryUrl` and downloads that page once.
     *
     * The returned page keeps `$entryUrl` unchanged and an empty hop list. A rejected URL and a failed download are thrown as they are.
     *
     * @throws UrlGuardException When the string cannot be parsed, local rules reject it, or the address check refuses it
     * @throws HttpFetcherException When the download fails, including when the response is a redirect
     */
    public function fetch(string $entryUrl): GuardedPage
    {
        $result = $this->urlGuard->guard($entryUrl);
        $this->logger->info('URL accepted', ['url' => $entryUrl]);

        $page = $this->httpFetcher->fetch($result->safe);
        $this->logger->info('Page fetched', ['requestUri' => $page->requestUri]);

        return new GuardedPage($page, $entryUrl, []);
    }
}
