<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Success body of the archive command: where the page lives and what it is called.
 */
#[Exclude]
#[OA\Schema(required: ['url', 'title'])]
final readonly class ArchiveResponse
{
    /**
     * Creates the success body.
     */
    public function __construct(
        /** The URL of the archived page. */
        #[OA\Property]
        public string $url,
        /** The title of the archived article. */
        #[OA\Property]
        public string $title,
    ) {
    }
}
