<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Body of the archive command. It is not an archive, an article, or a URL pointer.
 */
#[Exclude]
#[OA\Schema(required: ['url'])]
final class CreateArchiveBody
{
    /**
     * Null is refused. An empty string is kept.
     */
    public function __construct(
        #[NotNull]
        #[OA\Property(property: 'url', type: 'string')]
        public ?string $url = null,
    ) {
    }
}
