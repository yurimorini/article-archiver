<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reads one page of the archives of the owner whose store it is given.
 *
 * `$page = $service->page($query);`
 */
#[Exclude]
final class ArchiveListService
{
    /**
     * Creates the service from the store of one owner and the domain logger.
     */
    public function __construct(
        /** The store of the owner this service reads. */
        private ArchiveStore $store,
        /** The domain logger that records a failed page read. */
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
     * Logs that reading `$query` failed.
     */
    private function failed(ArchivePageQuery $query, \Throwable $error): void
    {
        $this->log('warning', 'archive.list_failed', $this->context($query, $error));
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
            // A failed write must not replace the page failure.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ArchivePageQuery $query, \Throwable $error): array
    {
        $context = [
            'sort' => $query->sort->value,
            'dir' => $query->dir->value,
            'page' => $query->page,
            'size' => $query->size,
        ];
        $location = $this->store->location();
        if (is_string($location)) {
            $context['store'] = $location;
        }
        $context['exception'] = $error;

        return $context;
    }
}
