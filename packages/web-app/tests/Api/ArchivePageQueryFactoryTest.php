<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Api\ArchivePageQueryFactory;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchiveSort;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Proves that the factory forwards the query strings and the maximum it was given.
 */
final class ArchivePageQueryFactoryTest extends TestCase
{
    public function test_absent_strings_apply_the_defaults(): void
    {
        $query = $this->query(100, new ListArchivesQuery(null, null, null, null));

        self::assertSame(ArchiveSort::Archived, $query->sort);
        self::assertSame(ArchiveDirection::Desc, $query->dir);
        self::assertSame(1, $query->page);
        self::assertSame(10, $query->size);
    }

    public function test_present_strings_are_forwarded(): void
    {
        $query = $this->query(100, new ListArchivesQuery('archived', 'asc', '2', '1'));

        self::assertSame(ArchiveSort::Archived, $query->sort);
        self::assertSame(ArchiveDirection::Asc, $query->dir);
        self::assertSame(2, $query->page);
        self::assertSame(1, $query->size);
    }

    public function test_size_at_one_hundred_is_kept(): void
    {
        $query = $this->query(100, new ListArchivesQuery(null, null, null, '100'));

        self::assertSame(100, $query->size);
    }

    public function test_size_above_one_hundred_throws(): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        $this->query(100, new ListArchivesQuery(null, null, null, '101'));
    }

    public function test_size_above_a_maximum_of_three_throws(): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        $this->query(3, new ListArchivesQuery(null, null, null, '4'));
    }

    public function test_size_at_a_maximum_of_three_is_kept(): void
    {
        $query = $this->query(3, new ListArchivesQuery(null, null, null, '3'));

        self::assertSame(3, $query->size);
    }

    private function query(int $maxSize, ListArchivesQuery $dto): ArchivePageQuery
    {
        return new ArchivePageQueryFactory($maxSize)->from($dto);
    }
}
