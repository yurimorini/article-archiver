<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

/**
 * Builds the `Authorization` header a test sends to pass the bearer firewall.
 *
 * `['HTTP_AUTHORIZATION' => Bearer::header()]`
 */
final class Bearer
{
    /**
     * Returns `Bearer <secret>` for the secret that `.env.test` sets in `ELEANOR_BEARER_TOKEN`.
     */
    public static function header(): string
    {
        $secret = $_SERVER['ELEANOR_BEARER_TOKEN'] ?? null;

        if (!is_string($secret) || $secret === '') {
            throw new \LogicException('ELEANOR_BEARER_TOKEN is not set for the test environment.');
        }

        return 'Bearer ' . $secret;
    }
}
