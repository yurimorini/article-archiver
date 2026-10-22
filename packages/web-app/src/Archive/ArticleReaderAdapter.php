<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Yumo\ArtcleReader\Orchestrator\Orchestrator;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;

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
     * Fetches `$url` and returns the purified article.
     *
     * A library failure and a page with no article become an `ArchiveException`.
     *
     * @throws ArchiveException
     */
    public function fetch(UrlPointer $url): Article
    {
        $failures = new ArchiveFailureMapper();

        try {
            $outcome = $this->orchestrator->fetchArticle($url->toString());
        } catch (OrchestratorException $failure) {
            throw $failures->fromOrchestrator($failure);
        }

        if ($outcome instanceof PipelineNoContent) {
            throw $failures->fromEmpty($outcome);
        }

        $document = $outcome->document;

        return new Article(
            $document->title->raw(),
            $document->excerpt?->raw(),
            $document->siteName?->raw(),
            $document->html,
        );
    }
}
