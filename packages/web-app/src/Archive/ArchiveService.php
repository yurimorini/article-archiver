<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
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
     * Creates the service from the store of one owner and the fetcher that downloads pages.
     */
    public function __construct(
        /** The store of the owner this service works for. */
        private ArchiveStore $store,
        /** The fetcher that downloads and purifies a page. */
        private ArticleFetcher $fetcher,
    ) {
    }

    /**
     * Returns the stored archive of `$url`, fetching and storing the page when there is none.
     *
     * @throws ArchiveException When the page cannot be fetched or has no article
     */
    public function archive(string $url): ArchiveResult
    {
        $stored = $this->store->find($url);
        if ($stored !== null) {
            return new ArchiveResult($stored, ArchiveOutcome::AlreadyStored);
        }

        $archive = $this->fromSuccess($url, $this->fetch($url));
        $this->store->save($archive);

        return new ArchiveResult($archive, ArchiveOutcome::Written);
    }

    /**
     * Fetches `$url`. A thrown failure and an empty outcome both become an `ArchiveException`.
     *
     * @throws ArchiveException
     */
    private function fetch(string $url): PipelineSuccess
    {
        try {
            $outcome = $this->fetcher->fetch($url);
        } catch (\Throwable $failure) {
            throw new ArchiveException('The page could not be fetched.', $failure);
        }

        if ($outcome instanceof PipelineNoContent) {
            throw new ArchiveException('The page has no article.');
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
