<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;
use Yumo\Eleanor\Archive\ArticleFetcher;

/**
 * Answers fetches from a table of outcomes and counts every call.
 *
 * A URL with no registered outcome is refused the way the library's URL check refuses a string that is not a usable URL.
 */
final class FakeArticleFetcher implements ArticleFetcher
{
    /** @var array<string, PipelineSuccess|PipelineNoContent|\Throwable> */
    private array $outcomes = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Registers the outcome returned for `$url`.
     */
    public function willReturn(string $url, PipelineSuccess|PipelineNoContent $outcome): void
    {
        $this->outcomes[$url] = $outcome;
    }

    /**
     * Registers the failure thrown for `$url`.
     */
    public function willThrow(string $url, \Throwable $failure): void
    {
        $this->outcomes[$url] = $failure;
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

    public function fetch(string $url): PipelineSuccess|PipelineNoContent
    {
        $this->counts[$url] = ($this->counts[$url] ?? 0) + 1;
        $outcome = $this->outcomes[$url] ?? new OrchestratorException(OrchestratorError::UrlGuard);

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
