<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Error body of every failed API call: a stable code and a message for people.
 */
#[Exclude]
final readonly class ErrorResponse
{
    /**
     * Creates the error body.
     */
    public function __construct(
        /** The stable wire code that clients branch on. */
        public string $code,
        /** The human-readable explanation. */
        public string $message,
    ) {
    }
}
