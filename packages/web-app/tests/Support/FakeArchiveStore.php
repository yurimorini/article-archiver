<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveSummary;

/**
 * Keeps archives in an array, records every call, and can be told to fail.
 *
 * `findSummary` appends `find` to the shared log and `save` appends `save`. `find` is for the test to read
 * what was stored, so it leaves no trace.
 */
final class FakeArchiveStore implements ArchiveStore
{
    /** @var list<string> The URLs `findSummary` was asked for, in order. */
    public array $summaryLookups = [];

    /** @var list<string> The URLs of the archives `save` was given, in order. */
    public array $savedUrls = [];

    /** The throwable `findSummary` raises, or null. */
    public ?\Throwable $findSummaryFailure = null;

    /** The throwable `save` raises, or null. */
    public ?\Throwable $saveFailure = null;

    /** @var array<string, Archive> */
    private array $archives = [];

    /**
     * Creates an empty store that writes its calls to `$log`.
     *
     * @param \ArrayObject<int, string> $log
     */
    public function __construct(
        /** The log that the lock, the fetcher, and this store share. */
        private \ArrayObject $log = new \ArrayObject(),
    ) {
    }

    public function findSummary(string $url): ?ArchiveSummary
    {
        $this->log->append('find');
        $this->summaryLookups[] = $url;
        if ($this->findSummaryFailure !== null) {
            throw $this->findSummaryFailure;
        }

        $archive = $this->archives[$url] ?? null;
        if ($archive === null) {
            return null;
        }

        return new ArchiveSummary($archive->url, $archive->title, $archive->excerpt, $archive->siteName);
    }

    public function find(string $url): ?Archive
    {
        return $this->archives[$url] ?? null;
    }

    public function save(Archive $archive): void
    {
        $this->log->append('save');
        $this->savedUrls[] = $archive->url;
        if ($this->saveFailure !== null) {
            throw $this->saveFailure;
        }

        $this->archives[$archive->url] = $archive;
    }
}
