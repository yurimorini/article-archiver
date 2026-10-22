<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Security;

/**
 * The bearer secret is not configured. This is a server failure, not a caller failure.
 */
final class BearerNotConfigured extends \RuntimeException
{
    /**
     * Creates the failure with the message that names the missing variable.
     */
    public function __construct()
    {
        parent::__construct('ELEANOR_BEARER_TOKEN is empty.');
    }
}
