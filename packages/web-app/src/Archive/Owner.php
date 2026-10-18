<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The person an archive belongs to, identified by an email address.
 *
 * `$owner = new Owner('ada@example.com');`
 */
#[Exclude]
final readonly class Owner
{
    /**
     * Creates an owner from the email address that identifies them.
     */
    public function __construct(
        /** The email address that identifies the owner. */
        public string $email,
    ) {
    }
}
