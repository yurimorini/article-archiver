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
     * Returns the light fields of `$owner` stored for `$url`, or null.
     */
    public function findSummary(Owner $owner, string $url): ?ArchiveSummary
    {
        $archive = $this->find($owner, $url);
        if ($archive === null) {
            return null;
        }

        return new ArchiveSummary($archive->url, $archive->title, $archive->excerpt, $archive->siteName);
    }

    /**
     * Returns the archive of `$owner` stored for `$url`, or null.
     */
    public function find(Owner $owner, string $url): ?Archive
    {
        return $this->archives[$owner->email][$url] ?? null;
    }

    /**
     * Stores `$archive` for `$owner` under the archive's URL.
     */
    public function save(Owner $owner, Archive $archive): void
    {
        $this->archives[$owner->email][$archive->url] = $archive;
    }
}
