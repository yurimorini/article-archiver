<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The identity of one stored archive, read from one path segment.
 *
 * The segment must be a base-10 integer of at least 1 that fits in a PHP int. A sign, a decimal point, a leading zero, surrounding space, or a trailing newline is rejected.
 */
#[Exclude]
final readonly class ArchiveId
{
    /**
     * Creates the id. Callers use `from`.
     */
    private function __construct(
        /** The positive integer that names the archive. */
        public int $value,
    ) {
    }

    /**
     * Reads `$segment` as a positive integer that fits in a PHP int.
     *
     * @throws InvalidArchiveQuery When `$segment` is not that integer
     */
    public static function from(string $segment): self
    {
        if (preg_match('/^[1-9][0-9]*\z/', $segment) !== 1) {
            throw new InvalidArchiveQuery();
        }

        $limit = (string) PHP_INT_MAX;

        // Same length, so byte order is numeric order. This rejects a digit string above PHP_INT_MAX.
        if (strlen($segment) > strlen($limit) || (strlen($segment) === strlen($limit) && strcmp($segment, $limit) > 0)) {
            throw new InvalidArchiveQuery();
        }

        return new self((int) $segment);
    }
}
