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
     *
     * A failure is not wrapped.
     */
    public function findSummary(UrlPointer $url): ?ArchiveSummary;

    /**
     * Returns the archive stored for `$url`, or null when this owner has none.
     *
     * A failure is not wrapped.
     */
    public function find(UrlPointer $url): ?Archive;

    /**
     * Returns one page of this owner's archives and the count of every archive.
     *
     * A missing file is an empty page and is not created. Rows are ordered by insert time, then by id.
     * A file that names another owner is refused the same way as `find`.
     *
     * @throws ArchiveException When the page cannot be read, with `Unexpected`
     */
    public function findPage(ArchivePageQuery $query): ArchivePageSlice;

    /**
     * Stores `$archive` under its URL for this owner and returns the stored archive.
     *
     * The id on `$archive` is ignored. The returned id is the one this store assigned.
     * A second save of the same URL keeps that id and the insert time.
     *
     * @throws ArchiveException When the archive cannot be stored, with `NotWritten`
     */
    public function save(Archive $archive): Archive;

    /**
     * Returns the file this store writes, or null when the store has no file.
     *
     * This method does not open the file.
     */
    public function location(): ?string;
}
