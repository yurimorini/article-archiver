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
        /** The database identity of the stored archive. */
        public int $id,
    ) {
    }

    /**
     * Copies the light fields of `$archive`, including its id. The HTML stays out.
     *
     * @throws \LogicException When `$archive` has no id
     */
    public static function fromArchive(Archive $archive): self
    {
        if ($archive->id === null) {
            throw new \LogicException('The archive has no id.');
        }

        return new self($archive->url, $archive->title, $archive->excerpt, $archive->siteName, $archive->id);
    }
}
