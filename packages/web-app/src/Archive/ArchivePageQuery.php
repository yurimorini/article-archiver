<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One legal page of an archive list: sort, direction, page number, and page size.
 *
 * A null string takes the default. Any other string must be the token or a legal integer. `size` above the maximum is rejected, not shortened.
 */
#[Exclude]
final readonly class ArchivePageQuery
{
    /** The page used when the page string is absent. */
    private const int DEFAULT_PAGE = 1;

    /** The size used when the size string is absent. */
    private const int DEFAULT_SIZE = 10;

    /**
     * Creates one applied page query.
     */
    private function __construct(
        /** The only sort, the insert time. */
        public ArchiveSort $sort,
        /** Ascending or descending. */
        public ArchiveDirection $dir,
        /** The 1-based page number. */
        public int $page,
        /** How many rows the page holds. */
        public int $size,
    ) {
    }

    /**
     * Applies defaults for null strings and rejects every other illegal value.
     *
     * `$maxSize` is the largest accepted `size`. A larger `size` is rejected, not shortened. The offset `(page - 1) * size` must fit in a PHP int.
     *
     * @throws InvalidArchiveQuery When a present string is not the token or a legal integer, when `size` exceeds `$maxSize`, or when the offset does not fit in a PHP int
     */
    public static function from(?string $sort, ?string $dir, ?string $page, ?string $size, int $maxSize): self
    {
        $resolvedSize = self::size($size, $maxSize);

        return new self(
            self::sort($sort),
            self::direction($dir),
            self::page($page, $resolvedSize),
            $resolvedSize,
        );
    }

    /**
     * @throws InvalidArchiveQuery
     */
    private static function sort(?string $sort): ArchiveSort
    {
        if ($sort === null) {
            return ArchiveSort::Archived;
        }

        return ArchiveSort::tryFrom($sort) ?? throw new InvalidArchiveQuery();
    }

    /**
     * @throws InvalidArchiveQuery
     */
    private static function direction(?string $dir): ArchiveDirection
    {
        if ($dir === null) {
            return ArchiveDirection::Desc;
        }

        return ArchiveDirection::tryFrom($dir) ?? throw new InvalidArchiveQuery();
    }

    /**
     * @throws InvalidArchiveQuery
     */
    private static function page(?string $page, int $size): int
    {
        if ($page === null) {
            return self::DEFAULT_PAGE;
        }

        $parsed = self::integer($page);

        if ($parsed > 1 && ($parsed - 1) > intdiv(PHP_INT_MAX, $size)) {
            throw new InvalidArchiveQuery();
        }

        return $parsed;
    }

    /**
     * @throws InvalidArchiveQuery
     */
    private static function size(?string $size, int $maxSize): int
    {
        if ($size === null) {
            return self::DEFAULT_SIZE;
        }

        $parsed = self::integer($size);

        if ($parsed > $maxSize) {
            throw new InvalidArchiveQuery();
        }

        return $parsed;
    }

    /**
     * Reads a base-10 integer string of at least 1 that fits in a PHP int.
     *
     * A sign, a decimal point, a leading zero, or surrounding space is rejected.
     *
     * @throws InvalidArchiveQuery
     */
    private static function integer(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArchiveQuery();
        }

        $limit = (string) PHP_INT_MAX;

        // Same length, so byte order is numeric order. This rejects a digit string above PHP_INT_MAX.
        if (strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            throw new InvalidArchiveQuery();
        }

        return (int) $value;
    }
}
