<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Orchestrator;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\ArtcleReader\HttpFetcher\FetchedPage;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\HttpFetcher\RedirectResponse;
use Yumo\ArtcleReader\UrlGuard\SafeFetchTarget;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;

/**
 * This class checks one caller URL, downloads that page, and follows redirects up to a fixed number of hops.
 *
 * The caller string is stored unchanged. This class follows at most `$maxRedirectHops` redirect hops. Each
 * next URL is checked by the guard before it is requested, so a redirect cannot reach an address the guard
 * would refuse. A redirect that cannot be followed is thrown as a failed download.
 *
 * `$guarded = (new GuardedFetch($urlGuard, $httpFetcher, maxRedirectHops: 3))->fetch($entryUrl);`
 */
final class GuardedFetch
{
    /** This list holds the status codes whose `Location` header names the next URL to request. */
    private const array FOLLOWED_STATUS_CODES = [301, 302, 303, 307, 308];

    /**
     * This constructor stores the guard, the downloader, the logger, and the hop cap.
     *
     * When `$logger` is omitted, log records are discarded. When `$maxRedirectHops` is `0`, no redirect is followed.
     */
    public function __construct(
        /** This value checks every URL before it is requested. */
        private UrlGuard $urlGuard,
        /** This value downloads one guarded URL per call. */
        private HttpFetcher $httpFetcher,
        /** This value receives an info record after each URL is accepted and after the page is downloaded. */
        private LoggerInterface $logger = new NullLogger(),
        /** This value is the largest number of redirect hops that may be followed. */
        private int $maxRedirectHops = 0,
    ) {
    }

    /**
     * This method checks `$entryUrl`, downloads it, and follows redirect hops until a page arrives or the cap is spent.
     *
     * The returned page keeps `$entryUrl` unchanged. Its hop list holds the request URL of every page that redirected,
     * in order. A rejected URL and a failed download, including one on a later hop, are thrown as they are.
     *
     * @throws UrlGuardException When the string cannot be parsed, local rules reject it, or the address check refuses it, for the entry URL or any hop
     * @throws HttpFetcherException When a download fails, a redirect cannot be followed, or the hop cap is exhausted
     */
    public function fetch(string $entryUrl): GuardedPage
    {
        $target = $this->urlGuard->guard($entryUrl)->safe;
        $this->logger->info('URL accepted', ['url' => $entryUrl]);
        $hopHistory = [];

        while (true) {
            $response = $this->httpFetcher->fetch($target);
            if ($response instanceof FetchedPage) {
                $this->logger->info('Page fetched', ['requestUri' => $response->requestUri]);

                return new GuardedPage($response, $entryUrl, $hopHistory);
            }

            $this->assertHopAllowed($response, count($hopHistory));
            $hopHistory[] = $target->requestUri;
            $target = $this->guardNextHop($target, $response);
        }
    }

    /**
     * This method throws unless `$response` is a redirect that this fetch may follow now.
     *
     * @throws HttpFetcherException With `Redirect` when the response is not followable or the cap is `0`, and with `RedirectLimit` when the cap is spent
     */
    private function assertHopAllowed(RedirectResponse $response, int $hopsFollowed): void
    {
        if (!$this->isFollowable($response)) {
            throw $this->redirectNotFollowed($response);
        }
        if ($hopsFollowed < $this->maxRedirectHops) {
            return;
        }
        if ($this->maxRedirectHops === 0) {
            throw $this->redirectNotFollowed($response);
        }

        throw new HttpFetcherException(
            HttpFetcherError::RedirectLimit,
            sprintf('HTTP redirect hop cap is exhausted (status %d)', $response->statusCode),
            statusCode: $response->statusCode,
        );
    }

    /**
     * This method returns whether the status names a next URL and the `Location` header holds one.
     */
    private function isFollowable(RedirectResponse $response): bool
    {
        return in_array($response->statusCode, self::FOLLOWED_STATUS_CODES, true)
            && trim($response->location) !== '';
    }

    private function redirectNotFollowed(RedirectResponse $response): HttpFetcherException
    {
        return new HttpFetcherException(
            HttpFetcherError::Redirect,
            sprintf('HTTP redirect (status %d) is not followed', $response->statusCode),
            statusCode: $response->statusCode,
        );
    }

    /**
     * This method resolves the redirect `Location` against the page that sent it and checks the result with the guard.
     *
     * @throws UrlGuardException When the `Location` cannot be parsed or the guard refuses the resolved URL
     */
    private function guardNextHop(SafeFetchTarget $current, RedirectResponse $response): SafeFetchTarget
    {
        $resolved = $this->resolveLocation($current->requestUri, $response->location);
        $next = $this->urlGuard->guard($resolved)->safe;
        $this->logger->info('URL accepted', ['url' => $resolved]);

        return $next;
    }

    /**
     * This method resolves `$location` against `$baseUri` by the reference rules of RFC 3986.
     *
     * The base fragment is dropped first, because a fragment is never sent to a server and a redirect does not carry it over.
     *
     * @throws UrlGuardException With `Syntax` when either string is not a valid URI reference
     */
    private function resolveLocation(string $baseUri, string $location): string
    {
        try {
            $base = new Uri($baseUri)->withFragment('');

            return (string) UriResolver::resolve($base, new Uri(trim($location)));
        } catch (\InvalidArgumentException $exception) {
            throw new UrlGuardException(UrlGuardError::Syntax, '', $exception);
        }
    }
}
