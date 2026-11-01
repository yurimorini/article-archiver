<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * JSON document of one archive page, including the path of the next page when one exists.
 */
#[Exclude]
final readonly class ArchiveListResponse
{
    /**
     * Creates the list document.
     *
     * @param list<ArchiveListItemResponse> $items
     */
    public function __construct(
        /** The applied sort token. */
        public string $sort,
        /** The applied direction token. */
        public string $dir,
        /** The 1-based page number. */
        public int $page,
        /** How many rows the page holds. */
        public int $size,
        /** The rows on this page, in order. */
        public array $items,
        /** How many archives this owner has, including rows outside this page. */
        public int $total,
        /** The path of the next page, or null when this page is the last. */
        public ?string $next,
    ) {
    }
}
