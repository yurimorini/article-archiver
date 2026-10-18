<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\InMemory;

use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveStoreFactory;
use Yumo\Eleanor\Archive\Owner;

/**
 * Makes in-memory stores that share one shelf.
 *
 * This is a temporary stand-in for the SQLite store factory of a later slice. It keeps no state of its own.
 */
final class InMemoryArchiveStoreFactory implements ArchiveStoreFactory
{
    /**
     * Creates the factory around the shelf that all its stores share.
     */
    public function __construct(
        /** The shelf every store made here reads and writes. */
        private InMemoryArchiveShelf $shelf,
    ) {
    }

    /**
     * Returns a new in-memory store for `$owner` on the shared shelf.
     */
    public function create(Owner $owner): ArchiveStore
    {
        return new InMemoryArchiveStore($owner, $this->shelf);
    }
}
