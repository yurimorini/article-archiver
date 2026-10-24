<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\Article;
use Yumo\Eleanor\Archive\ArticleFetcher;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Answers fetches from a table of articles and counts every call.
 *
 * A URL with no registered outcome is refused as `url_rejected`.
 */
final class FakeArticleFetcher implements ArticleFetcher
{
    /** @var array<string, Article|\Throwable> */
    private array $outcomes = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Registers the article returned for `$url`.
     */
    public function willReturn(string $url, Article $article): void
    {
        $this->outcomes[$url] = $article;
    }

    /**
     * Registers the archive failure thrown for `$url`.
     */
    public function willThrow(string $url, ArchiveException $failure): void
    {
        $this->outcomes[$url] = $failure;
    }

    /**
     * Registers a throwable that is not an archive failure for `$url`.
     */
    public function willThrowUnexpected(string $url, \Throwable $error): void
    {
        $this->outcomes[$url] = $error;
    }

    /**
     * Returns how many fetches were asked for `$url`, or for every URL when it is null.
     */
    public function fetchCount(?string $url = null): int
    {
        if ($url === null) {
            return array_sum($this->counts);
        }

        return $this->counts[$url] ?? 0;
    }

    public function fetch(UrlPointer $url): Article
    {
        $key = $url->toString();
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $outcome = $this->outcomes[$key] ?? new ArchiveException(ArchiveFailure::UrlRejected);

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
