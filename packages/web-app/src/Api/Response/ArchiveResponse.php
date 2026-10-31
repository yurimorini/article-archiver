<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\ArchiveResult;

/**
 * Success body of the archive command: its id, where the page lives, and what it is called.
 */
#[Exclude]
final readonly class ArchiveResponse
{
    /**
     * Creates the success body.
     */
    public function __construct(
        /** The database identity of the stored archive. */
        public int $id,
        /** The URL of the archived page. */
        public string $url,
        /** The title of the archived article. */
        public string $title,
    ) {
    }

    /**
     * Copies the id, the URL, and the title. The excerpt, the site name, the outcome, and the HTML stay out.
     */
    public static function fromArchiveResult(ArchiveResult $result): self
    {
        return new self($result->summary->id, $result->summary->url, $result->summary->title);
    }
}
