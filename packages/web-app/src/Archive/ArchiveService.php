<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;

/**
 * Archives the page behind a URL for the owner of the store it is given.
 *
 * `$result = $service->archive('https://example.com/a');`
 */
#[Exclude]
final class ArchiveService
{
    /**
     * Creates the service from the store of one owner, the fetcher that downloads pages, and the failure mapper.
     */
    public function __construct(
        /** The store of the owner this service works for. */
        private ArchiveStore $store,
        /** The fetcher that downloads and purifies a page. */
        private ArticleFetcher $fetcher,
        /** The mapper that turns library failures into archive failures. */
        private ArchiveFailureMapper $failures = new ArchiveFailureMapper(),
    ) {
    }

    /**
     * Returns the stored summary of `$url`, fetching and storing the page when there is none.
     *
     * @throws ArchiveException When the library fails to fetch the page or the page has no article
     */
    public function archive(string $url): ArchiveResult
    {
        $stored = $this->store->findSummary($url);
        if ($stored !== null) {
            return new ArchiveResult($stored, ArchiveOutcome::AlreadyStored);
        }

        $archive = $this->fromSuccess($url, $this->fetch($url));
        $this->store->save($archive);

        return new ArchiveResult(
            new ArchiveSummary($archive->url, $archive->title, $archive->excerpt, $archive->siteName),
            ArchiveOutcome::Written,
        );
    }

    /**
     * Fetches `$url`. A failed fetch and an empty outcome both become an `ArchiveException`.
     * Any other throwable is not an archive failure and passes through.
     *
     * @throws ArchiveException
     */
    private function fetch(string $url): PipelineSuccess
    {
        try {
            $outcome = $this->fetcher->fetch($url);
        } catch (OrchestratorException $failure) {
            throw $this->failures->fromOrchestrator($failure);
        }

        if ($outcome instanceof PipelineNoContent) {
            throw $this->failures->fromEmpty($outcome);
        }

        return $outcome;
    }

    /**
     * Builds the archive of `$url` from a fetched article. Warnings are not kept.
     */
    private function fromSuccess(string $url, PipelineSuccess $success): Archive
    {
        $document = $success->document;

        return new Archive(
            $url,
            $document->title->raw(),
            $document->excerpt?->raw(),
            $document->siteName?->raw(),
            $document->html,
        );
    }
}
