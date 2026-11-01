<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One page of an owner's archives: the applied query, the rows, and the total.
 *
 * The page has no next link.
 */
#[Exclude]
final readonly class ArchivePage
{
    /**
     * Creates one page from the applied query and the rows the store returned.
     *
     * @param list<ArchiveSummary> $items
     */
    public function __construct(
        /** The applied sort. */
        public ArchiveSort $sort,
        /** The applied direction. */
        public ArchiveDirection $dir,
        /** The 1-based page number. */
        public int $page,
        /** How many rows the page holds. */
        public int $size,
        /** The summaries on this page, in order. */
        public array $items,
        /** How many archives this owner has, including rows outside this page. */
        public int $total,
    ) {
    }
}
