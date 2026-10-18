<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Owner;

use Yumo\Eleanor\Archive\Owner;

/**
 * Answers every call with the owner whose email is configured.
 *
 * A later slice replaces it with a provider that reads the authenticated caller.
 */
final class ConfiguredOwnerProvider implements OwnerProvider
{
    public function __construct(
        /** The configured owner email. */
        private string $email,
    ) {
    }

    public function owner(): Owner
    {
        return new Owner($this->email);
    }
}
