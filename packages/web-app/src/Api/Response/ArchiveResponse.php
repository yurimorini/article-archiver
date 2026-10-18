<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\ArchiveResult;

/**
 * Success body of the archive command: where the page lives and what it is called.
 */
#[Exclude]
final readonly class ArchiveResponse
{
    /**
     * Creates the success body.
     */
    public function __construct(
        /** The URL of the archived page. */
        public string $url,
        /** The title of the archived article. */
        public string $title,
    ) {
    }

    /**
     * Copies the URL and the title. The excerpt, the site name, and the outcome stay out.
     */
    public static function fromArchiveResult(ArchiveResult $result): self
    {
        return new self($result->summary->url, $result->summary->title);
    }
}
