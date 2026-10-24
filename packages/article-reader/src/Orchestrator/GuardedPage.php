<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Orchestrator;

use Yumo\ArtcleReader\HttpFetcher\FetchedPage;

/**
 * This class is one downloaded page, the caller URL, and any redirect hops already recorded.
 *
 * `$originalUrl` is the string the caller passed, including surrounding whitespace. `$hopHistory` lists hop URLs in order and is empty when none were recorded.
 *
 * `$guarded = $guardedFetch->fetch($entryUrl);`
 */
final readonly class GuardedPage
{
    /**
     * This constructor stores the downloaded page, the caller URL, and the hop list.
     *
     * @param list<string> $hopHistory
     */
    public function __construct(
        /** This value holds the downloaded page. */
        public FetchedPage $page,
        /** This value holds the URL string passed into the fetch, stored unchanged. */
        public string $originalUrl,
        /** This value holds redirect hop URLs recorded during the fetch, in order. */
        public array $hopHistory,
    ) {
    }
}
