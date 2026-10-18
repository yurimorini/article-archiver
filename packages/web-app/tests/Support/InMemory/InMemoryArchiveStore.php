<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support\InMemory;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Keeps one owner's archives on an in-memory shelf.
 *
 * Test support that stands in for the SQLite store.
 */
#[Exclude]
final class InMemoryArchiveStore implements ArchiveStore
{
    /**
     * Creates a store that works on the archives of `$owner` held by `$shelf`.
     */
    public function __construct(
        /** The owner whose archives this store reads and writes. */
        private Owner $owner,
        /** The shelf that holds the rows. */
        private InMemoryArchiveShelf $shelf,
    ) {
    }

    /**
     * Returns the light fields this owner stored for `$url`, or null when there is none.
     */
    public function findSummary(UrlPointer $url): ?ArchiveSummary
    {
        return $this->shelf->findSummary($this->owner, $url->toString());
    }

    /**
     * Returns the archive this owner stored for `$url`, or null when there is none.
     */
    public function find(UrlPointer $url): ?Archive
    {
        return $this->shelf->find($this->owner, $url->toString());
    }

    /**
     * Stores `$archive` on the shelf under its URL for this owner.
     */
    public function save(Archive $archive): void
    {
        $this->shelf->save($this->owner, $archive);
    }

    /**
     * Returns null. This store has no file.
     */
    public function location(): ?string
    {
        return null;
    }
}
