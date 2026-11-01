<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\Archive;

/**
 * JSON document of one stored archive, including the purified HTML.
 */
#[Exclude]
final readonly class ArchiveDetailResponse
{
    /**
     * Creates the detail document. `html` is last so the serializer writes it last.
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
        /** The UTC insert time. */
        public string $archived,
        /** The purified article HTML. */
        public string $html,
    ) {
    }

    /**
     * Copies the fields of `$archive`, including the HTML.
     *
     * @throws \LogicException When `$archive` has no id or no insert time
     */
    public static function fromArchive(Archive $archive): self
    {
        if ($archive->id === null) {
            throw new \LogicException('The archive has no id.');
        }

        if ($archive->archived === null) {
            throw new \LogicException('The archive has no insert time.');
        }

        return new self(
            $archive->url,
            $archive->title,
            $archive->excerpt,
            $archive->siteName,
            $archive->id,
            $archive->archived,
            $archive->html,
        );
    }
}
