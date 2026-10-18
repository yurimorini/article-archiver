<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\Orchestrator\PipelineSuccess;
use Yumo\Eleanor\Archive\Sqlite\DirectoryException;

/**
 * Archives the page behind a URL for the owner of the store it is given.
 *
 * `$result = $service->archive(new UrlPointer('https://example.com/a'));`
 */
#[Exclude]
final class ArchiveService
{
    /**
     * Creates the service from the store of one owner, the fetcher that downloads pages, the lock of that owner, the domain logger, and the failure mapper.
     */
    public function __construct(
        /** The store of the owner this service works for. */
        private ArchiveStore $store,
        /** The fetcher that downloads and purifies a page. */
        private ArticleFetcher $fetcher,
        /** The lock that lets one caller at a time work on one URL. */
        private ArchiveLock $lock,
        /** The domain logger that records what this command does. */
        private LoggerInterface $logger,
        /** The mapper that turns library failures into archive failures. */
        private ArchiveFailureMapper $failures = new ArchiveFailureMapper(),
    ) {
    }

    /**
     * Returns the stored summary of `$url`, fetching and storing the page when there is none.
     *
     * The lookup, the fetch, and the save run while one caller holds the lock on the URL.
     *
     * @throws ArchiveException When the library fails to fetch the page, the page has no article, or the archive cannot be written
     */
    public function archive(UrlPointer $url): ArchiveResult
    {
        return $this->lock->exclusive($url, fn (): ArchiveResult => $this->archiveLocked($url));
    }

    /**
     * Looks up, fetches, and saves `$url`. The caller holds the lock.
     *
     * @throws ArchiveException
     */
    private function archiveLocked(UrlPointer $url): ArchiveResult
    {
        $this->log('info', 'archive.start', $this->context($url));

        $stored = $this->store->findSummary($url);
        if ($stored !== null) {
            $this->log('info', 'archive.already_stored', $this->context($url));

            return new ArchiveResult($stored, ArchiveOutcome::AlreadyStored);
        }

        $this->log('info', 'archive.fetch', $this->context($url));

        try {
            $fetched = $this->fetch($url);
        } catch (ArchiveException $failure) {
            $this->log('warning', 'archive.failed', $this->context($url, $failure->failure->value));

            throw $failure;
        }

        $archive = $this->fromSuccess($url, $fetched);
        $this->save($url, $archive);
        $this->log('info', 'archive.written', $this->context($url, store: true));

        return new ArchiveResult(
            ArchiveSummary::fromArchive($archive),
            ArchiveOutcome::Written,
        );
    }

    /**
     * Fetches `$url`. A failed fetch and an empty outcome both become an `ArchiveException`.
     * Any other throwable is not an archive failure and passes through.
     *
     * @throws ArchiveException
     */
    private function fetch(UrlPointer $url): PipelineSuccess
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
    private function fromSuccess(UrlPointer $url, PipelineSuccess $success): Archive
    {
        $document = $success->document;

        return new Archive(
            $url->toString(),
            $document->title->raw(),
            $document->excerpt?->raw(),
            $document->siteName?->raw(),
            $document->html,
        );
    }

    /**
     * Stores `$archive`. A missing data directory and a database failure both become `ArchiveFailure::NotWritten`.
     *
     * @throws ArchiveException
     */
    private function save(UrlPointer $url, Archive $archive): void
    {
        try {
            $this->store->save($archive);
        } catch (DirectoryException $error) {
            $this->notWritten($url, $error);
        } catch (\PDOException $error) {
            $this->notWritten($url, $error);
        }
    }

    /**
     * Logs a save that did not stick, then raises `NotWritten` with `$error` as the cause.
     *
     * @throws ArchiveException
     */
    private function notWritten(UrlPointer $url, \Throwable $error): never
    {
        $this->log('warning', 'archive.failed', $this->context($url, ArchiveFailure::NotWritten->value, true));

        throw new ArchiveException(ArchiveFailure::NotWritten, $error);
    }

    /**
     * Logs one domain line. A logger that throws does not change the archive outcome.
     *
     * @param array<string, string> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // A failed write must not change the archive outcome.
        }
    }

    /**
     * @return array<string, string>
     */
    private function context(UrlPointer $url, ?string $code = null, bool $store = false): array
    {
        $context = ['url' => $url->toString()];
        if ($code !== null) {
            $context['code'] = $code;
        }
        if ($store) {
            $location = $this->store->location();
            if (is_string($location)) {
                $context['store'] = $location;
            }
        }

        return $context;
    }
}
