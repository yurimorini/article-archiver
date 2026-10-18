<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Builds the archive service of one owner. It keeps no state.
 *
 * `$service = $factory->create(new Owner('ada@example.com'));`
 */
final class ArchiveServiceFactory
{
    public function __construct(
        /** The fetcher every service shares. */
        private ArticleFetcher $fetcher,
        /** The factory that makes each owner's store. */
        private ArchiveStoreFactory $stores,
    ) {
    }

    /**
     * Returns a service that works on the archives of `$owner`.
     */
    public function create(Owner $owner): ArchiveService
    {
        return new ArchiveService($this->stores->create($owner), $this->fetcher);
    }
}
