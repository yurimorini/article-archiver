<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Yumo\ArtcleReader\Orchestrator\Orchestrator;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;

/**
 * Fetches pages with the ArticleReader library's orchestrator.
 */
final class ArticleReaderAdapter implements ArticleFetcher
{
    /**
     * Creates the adapter around the library orchestrator.
     */
    public function __construct(
        /** The library orchestrator that checks, downloads, extracts, and purifies a page. */
        private Orchestrator $orchestrator,
    ) {
    }

    /**
     * Fetches `$url` through the library orchestrator and returns its outcome unchanged.
     */
    public function fetch(UrlPointer $url): PipelineSuccess|PipelineNoContent
    {
        return $this->orchestrator->fetchArticle($url->toString());
    }
}
