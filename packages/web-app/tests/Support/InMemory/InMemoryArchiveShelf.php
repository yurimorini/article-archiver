<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support\InMemory;

use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;

/**
 * Holds every owner's archives in memory.
 *
 * Test support that stands in for the directory of owner database files.
 * It lives as long as its container, so a new process starts empty.
 */
final class InMemoryArchiveShelf
{
    /**
     * The archives by owner email, then by URL.
     *
     * @var array<string, array<string, Archive>>
     */
    private array $archives = [];

    /**
     * The next id for each owner email. The first archive of an owner gets 1.
     *
     * @var array<string, int>
     */
    private array $nextId = [];

    /**
     * Returns the light fields of `$owner` stored for `$url`, or null.
     */
    public function findSummary(Owner $owner, string $url): ?ArchiveSummary
    {
        $archive = $this->find($owner, $url);
        if ($archive === null) {
            return null;
        }

        return ArchiveSummary::fromArchive($archive);
    }

    /**
     * Returns the archive of `$owner` stored for `$url`, or null.
     */
    public function find(Owner $owner, string $url): ?Archive
    {
        return $this->archives[$owner->email][$url] ?? null;
    }

    /**
     * Stores `$archive` for `$owner` under the archive's URL and returns the stored archive.
     *
     * The first archive for an owner gets id 1. A URL that is already stored keeps its id and its insert time.
     * The id on `$archive` is ignored. A new row with no insert time is stamped in UTC.
     */
    public function save(Owner $owner, Archive $archive): Archive
    {
        $stored = new Archive(
            $archive->url,
            $archive->title,
            $archive->excerpt,
            $archive->siteName,
            $archive->html,
            $this->idFor($owner, $archive->url),
            $this->archivedFor($owner, $archive),
        );
        $this->archives[$owner->email][$archive->url] = $stored;

        return $stored;
    }

    /**
     * Returns the insert time already stored for `$archive`, or stamps one when the row is new.
     */
    private function archivedFor(Owner $owner, Archive $archive): string
    {
        $current = $this->archives[$owner->email][$archive->url] ?? null;
        if (is_string($current?->archived)) {
            return $current->archived;
        }

        return $archive->archived ?? gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Returns the id already stored for `$url`, or the next id for `$owner`.
     */
    private function idFor(Owner $owner, string $url): int
    {
        $current = $this->archives[$owner->email][$url] ?? null;
        if ($current?->id !== null) {
            return $current->id;
        }

        $id = $this->nextId[$owner->email] ?? 1;
        $this->nextId[$owner->email] = $id + 1;

        return $id;
    }
}
