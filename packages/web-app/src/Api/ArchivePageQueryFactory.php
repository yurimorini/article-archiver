<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Archive\ArchivePageQuery;

/**
 * Turns list query strings into an archive page query.
 *
 * Excluded from the container until `eleanor.archive_page_max_size` is bound to `$maxSize`. An unbound int would fail container compilation. The factory does not catch `InvalidArchiveQuery`.
 */
#[Exclude]
final readonly class ArchivePageQueryFactory
{
    /**
     * Creates the factory with the largest accepted page size.
     */
    public function __construct(private int $maxSize)
    {
    }

    /**
     * Reads the four strings and applies them with this factory's maximum.
     *
     * @throws \Yumo\Eleanor\Archive\InvalidArchiveQuery When a present string is illegal or `size` exceeds the maximum
     */
    public function from(ListArchivesQuery $query): ArchivePageQuery
    {
        return ArchivePageQuery::from($query->sort, $query->dir, $query->page, $query->size, $this->maxSize);
    }
}
