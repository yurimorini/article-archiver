<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Stops a probe whose token or owner does not match.
 */
final class Guard
{
    /**
     * Rejects an empty bearer token.
     */
    public static function token(string $token): void
    {
        if ($token === '') {
            throw new \InvalidArgumentException('The bearer token is empty.');
        }
    }

    /**
     * Returns the lowercased parameter email.
     *
     * A configured owner that differs after the same lowercasing is a broken probe.
     */
    public static function owner(string $parameterEmail, ?string $configured): string
    {
        $owner = mb_strtolower($parameterEmail);
        if ($configured !== null && mb_strtolower($configured) !== $owner) {
            throw new \InvalidArgumentException('The probe owner does not match the parameter email.');
        }

        return $owner;
    }
}
