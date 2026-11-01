<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support\InMemory;

use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchivePageSlice;
use Yumo\Eleanor\Archive\ArchiveSort;
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
     * The UTC insert time a new row gets, or null to read the clock.
     *
     * A test sets this so rows can share one insert time.
     */
    public ?string $now = null;

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
     * Returns one page of `$owner`'s archives and the count of every archive.
     *
     * The page is ordered by insert time, then by id, both in `$query`'s direction.
     */
    public function findPage(Owner $owner, ArchivePageQuery $query): ArchivePageSlice
    {
        $archived = match ($query->sort) {
            ArchiveSort::Archived => static fn (Archive $archive): string => (string) $archive->archived,
        };
        $sign = match ($query->dir) {
            ArchiveDirection::Asc => 1,
            ArchiveDirection::Desc => -1,
        };
        $archives = array_values($this->archives[$owner->email] ?? []);
        usort(
            $archives,
            static function (Archive $left, Archive $right) use ($archived, $sign): int {
                $byTime = strcmp($archived($left), $archived($right));
                if ($byTime !== 0) {
                    return $sign * $byTime;
                }

                return $sign * (($left->id ?? 0) <=> ($right->id ?? 0));
            },
        );

        $items = [];
        foreach (array_slice($archives, ($query->page - 1) * $query->size, $query->size) as $archive) {
            $items[] = ArchiveSummary::fromArchive($archive);
        }

        return new ArchivePageSlice($items, count($archives));
    }

    /**
     * Returns the archive of `$owner` stored for `$url`, or null.
     */
    public function find(Owner $owner, string $url): ?Archive
    {
        return $this->archives[$owner->email][$url] ?? null;
    }

    /**
     * Returns the light fields of `$owner` stored for `$id`, or null.
     */
    public function findSummaryById(Owner $owner, int $id): ?ArchiveSummary
    {
        $archive = $this->findById($owner, $id);
        if ($archive === null) {
            return null;
        }

        return ArchiveSummary::fromArchive($archive);
    }

    /**
     * Returns the archive of `$owner` stored for `$id`, or null.
     */
    public function findById(Owner $owner, int $id): ?Archive
    {
        foreach ($this->archives[$owner->email] ?? [] as $archive) {
            if ($archive->id === $id) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * Returns the HTML of `$owner` stored for `$id`, or null.
     *
     * Empty HTML is an empty string.
     */
    public function findHtmlById(Owner $owner, int $id): ?string
    {
        return $this->findById($owner, $id)?->html;
    }

    /**
     * Stores `$archive` for `$owner` under the archive's URL and returns the stored archive.
     *
     * The first archive for an owner gets id 1. A URL that is already stored keeps its id and its insert time.
     * The id on `$archive` is ignored. A new row with no insert time is stamped in UTC.
     * The stamp is `now` when that property is set.
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

        return $archive->archived ?? $this->now ?? gmdate('Y-m-d\TH:i:s\Z');
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
