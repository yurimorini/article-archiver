<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Dto;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Query string of one archive read. It is not an archive id and not a page query.
 *
 * `includeContent` stays null when the key is absent. There are no validation attributes.
 * The value `true` is the string `true`.
 */
#[Exclude]
final readonly class ArchiveReadQuery
{
    /**
     * Creates the query string value. Null means the key was absent.
     */
    public function __construct(
        /** The content flag, or null when the key is absent. */
        public ?string $includeContent,
    ) {
    }
}
