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
    public function __construct(
        /** The shelf every store made here reads and writes. */
        private InMemoryArchiveShelf $shelf,
    ) {
    }

    public function create(Owner $owner): ArchiveStore
    {
        return new InMemoryArchiveStore($owner, $this->shelf);
    }
}
