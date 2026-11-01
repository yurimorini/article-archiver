<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reads one page, or one archive, of the owner whose store it is given.
 *
 * `$page = $service->page($query);`
 * `$summary = $service->summary($id);`
 */
#[Exclude]
final class ArchiveReadService
{
    /**
     * Creates the service from the store of one owner and the domain logger.
     */
    public function __construct(
        /** The store of the owner this service reads. */
        private ArchiveStore $store,
        /** The domain logger that records a failed read. */
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns one page of this owner's archives for `$query`.
     *
     * A missing file is an empty page. Any throwable from the read is logged and rethrown.
     *
     * @throws \Throwable When the page cannot be read
     */
    public function page(ArchivePageQuery $query): ArchivePage
    {
        try {
            $slice = $this->store->findPage($query);
        } catch (\Throwable $error) {
            $this->failed($query, $error);

            throw $error;
        }

        return new ArchivePage(
            $query->sort,
            $query->dir,
            $query->page,
            $query->size,
            $slice->items,
            $slice->total,
        );
    }

    /**
     * Returns the light fields of archive `$id`, or null when this owner has none.
     *
     * Null is a miss and is not logged. Any throwable from the read is logged and rethrown.
     *
     * @throws \Throwable When the summary cannot be read
     */
    public function summary(ArchiveId $id): ?ArchiveSummary
    {
        try {
            return $this->store->findSummaryById($id);
        } catch (\Throwable $error) {
            $this->detailFailed($id, 'summary', $error);

            throw $error;
        }
    }

    /**
     * Returns archive `$id`, or null when this owner has none.
     *
     * Null is a miss and is not logged. Any throwable from the read is logged and rethrown.
     *
     * @throws \Throwable When the archive cannot be read
     */
    public function read(ArchiveId $id): ?Archive
    {
        try {
            return $this->store->findById($id);
        } catch (\Throwable $error) {
            $this->detailFailed($id, 'read', $error);

            throw $error;
        }
    }

    /**
     * Returns the stored HTML of archive `$id`, or null when this owner has none.
     *
     * Null is a miss and is not logged. Any throwable from the read is logged and rethrown.
     *
     * @throws \Throwable When the HTML cannot be read
     */
    public function html(ArchiveId $id): ?string
    {
        try {
            return $this->store->findHtmlById($id);
        } catch (\Throwable $error) {
            $this->detailFailed($id, 'html', $error);

            throw $error;
        }
    }

    /**
     * Logs that reading `$query` failed.
     */
    private function failed(ArchivePageQuery $query, \Throwable $error): void
    {
        $this->log('warning', 'archive.list_failed', $this->context($query, $error));
    }

    /**
     * Logs that reading `$id` for `$view` failed.
     */
    private function detailFailed(ArchiveId $id, string $view, \Throwable $error): void
    {
        $this->log('warning', 'archive.detail_failed', $this->detailContext($id, $view, $error));
    }

    /**
     * Logs one domain line. A logger that throws does not replace the failure.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // A failed write must not replace the read failure.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ArchivePageQuery $query, \Throwable $error): array
    {
        return $this->withStore([
            'sort' => $query->sort->value,
            'dir' => $query->dir->value,
            'page' => $query->page,
            'size' => $query->size,
        ], $error);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailContext(ArchiveId $id, string $view, \Throwable $error): array
    {
        return $this->withStore([
            'id' => $id->value,
            'view' => $view,
        ], $error);
    }

    /**
     * Adds the store path when the store has one, then the exception.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function withStore(array $context, \Throwable $error): array
    {
        $location = $this->store->location();
        if (is_string($location)) {
            $context['store'] = $location;
        }
        $context['exception'] = $error;

        return $context;
    }
}
