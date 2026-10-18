<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Keeps the archives of one owner. The owner is fixed when the store is made, so no method takes it.
 */
interface ArchiveStore
{
    /**
     * Returns the light fields stored for `$url`, or null when this owner has none.
     */
    public function findSummary(UrlPointer $url): ?ArchiveSummary;

    /**
     * Returns the archive stored for `$url`, or null when this owner has none.
     */
    public function find(UrlPointer $url): ?Archive;

    /**
     * Stores `$archive` under its URL for this owner.
     */
    public function save(Archive $archive): void;

    /**
     * Returns the file this store writes, or null when the store has no file.
     *
     * This method does not open the file.
     */
    public function location(): ?string;
}
