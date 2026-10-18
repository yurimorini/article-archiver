<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The light fields of one stored page, without the HTML.
 */
#[Exclude]
final readonly class ArchiveSummary
{
    /**
     * Creates the light fields of one stored page.
     */
    public function __construct(
        /** The URL string the client sent. It names the archive. */
        public string $url,
        /** The article title. It is empty when the page has none. */
        public string $title,
        /** The short description, or null when the page has none. */
        public ?string $excerpt,
        /** The name of the site, or null when the page has none. */
        public ?string $siteName,
    ) {
    }

    /**
     * Copies the light fields of `$archive`. The HTML stays out.
     */
    public static function fromArchive(Archive $archive): self
    {
        return new self($archive->url, $archive->title, $archive->excerpt, $archive->siteName);
    }
}
