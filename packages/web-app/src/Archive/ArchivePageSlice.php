<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One page of archive summaries and the count of every archive.
 *
 * `$items` is the page. `$total` counts every archive, so it can be larger than the page.
 */
#[Exclude]
final readonly class ArchivePageSlice
{
    /**
     * Creates one page of summaries and the count of every archive.
     *
     * @param list<ArchiveSummary> $items
     */
    public function __construct(
        /** The summaries on this page, in order. */
        public array $items,
        /** How many archives this owner has, including rows outside this page. */
        public int $total,
    ) {
    }
}
