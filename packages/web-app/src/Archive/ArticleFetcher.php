<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;

/**
 * Fetches one URL and returns the purified article or an empty outcome.
 *
 * It exists so the archive command does not depend on a final library class that tests cannot replace.
 */
interface ArticleFetcher
{
    /**
     * Fetches `$url`. A failure is thrown and a page with no article is returned as `PipelineNoContent`.
     */
    public function fetch(string $url): PipelineSuccess|PipelineNoContent;
}
