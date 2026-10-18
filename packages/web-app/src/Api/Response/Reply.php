<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * An HTTP status paired with the body to send.
 *
 * @template-covariant T of object
 */
#[Exclude]
final readonly class Reply
{
    /**
     * Creates the reply.
     *
     * @param T $body
     */
    public function __construct(
        /** The HTTP status code. */
        public int $status,
        /** @var T */
        public object $body,
    ) {
    }
}
