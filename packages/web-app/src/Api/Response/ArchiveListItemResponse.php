<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One row of the archive list: the light fields a client needs, without the HTML.
 */
#[Exclude]
final readonly class ArchiveListItemResponse
{
    /**
     * Creates one list row. `site` is the summary's site name.
     */
    public function __construct(
        /** The database identity of the stored archive. */
        public int $id,
        /** The URL of the archived page. */
        public string $url,
        /** The title of the archived article. */
        public string $title,
        /** The short description, or null when the page has none. */
        public ?string $excerpt,
        /** The name of the site, or null when the page has none. */
        public ?string $site,
        /** The UTC insert time. */
        public string $archived,
    ) {
    }
}
