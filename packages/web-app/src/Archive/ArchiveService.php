<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Archives the page behind a URL for the owner of the store it is given.
 *
 * `$result = $service->archive(new UrlPointer('https://example.com/a'));`
 */
#[Exclude]
final class ArchiveService
{
    /**
     * Creates the service from the store of one owner, the fetcher that downloads pages, the lock of that owner, and the domain logger.
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
        $stored = $this->store->findSummary($url);
        if ($stored !== null) {
            $this->alreadyStored($url);

            return new ArchiveResult($stored, ArchiveOutcome::AlreadyStored);
        }

        try {
            $fetched = $this->fetcher->fetch($url);
        } catch (ArchiveException $failure) {
            $this->failed($url, $failure);

            throw $failure;
        }

        $archive = $this->fromSuccess($url, $fetched);
        $this->save($url, $archive);
        $this->written($url);

        return new ArchiveResult(
            ArchiveSummary::fromArchive($archive),
            ArchiveOutcome::Written,
        );
    }

    /**
     * Logs that `$url` was already stored.
     */
    private function alreadyStored(UrlPointer $url): void
    {
        $this->log('info', 'archive.already_stored', $this->context($url));
    }

    /**
     * Builds the archive of `$url` from a fetched article.
     */
    private function fromSuccess(UrlPointer $url, Article $article): Archive
    {
        return new Archive(
            $url->toString(),
            $article->title,
            $article->excerpt,
            $article->siteName,
            $article->html,
        );
    }

    /**
     * Stores `$archive`. The store throws `ArchiveException` and this method logs and rethrows it.
     *
     * @throws ArchiveException
     */
    private function save(UrlPointer $url, Archive $archive): void
    {
        try {
            $this->store->save($archive);
        } catch (ArchiveException $error) {
            $this->failed($url, $error);

            throw $error;
        }
    }

    /**
     * Logs that the archive of `$url` was written.
     */
    private function written(UrlPointer $url): void
    {
        $this->log('info', 'archive.written', $this->context($url, store: true));
    }

    /**
     * Logs that archiving `$url` failed. A `NotWritten` failure includes the store path.
     */
    private function failed(UrlPointer $url, ArchiveException $failure): void
    {
        $store = $failure->failure === ArchiveFailure::NotWritten;

        $this->log('warning', 'archive.failed', $this->context($url, $failure->failure->value, $store));
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
