<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Dto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Query string of the archive list. It is not an archive page query.
 *
 * Each property stays null when its key is absent. There are no validation attributes.
 */
#[Exclude]
final readonly class ListArchivesQuery
{
    /**
     * Creates the query string values. Null means the key was absent.
     */
    public function __construct(
        /** The sort token, or null when the key is absent. */
        public ?string $sort,
        /** The direction token, or null when the key is absent. */
        public ?string $dir,
        /** The page number as text, or null when the key is absent. */
        public ?string $page,
        /** The page size as text, or null when the key is absent. */
        public ?string $size,
    ) {
    }
}
