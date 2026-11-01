<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchiveSort;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Proves that an archive page query applies defaults and rejects an illegal string.
 */
final class ArchivePageQueryTest extends TestCase
{
    public function test_absent_strings_apply_the_defaults(): void
    {
        $query = ArchivePageQuery::from(null, null, null, null, 100);

        self::assertSame(ArchiveSort::Archived, $query->sort);
        self::assertSame(ArchiveDirection::Desc, $query->dir);
        self::assertSame(1, $query->page);
        self::assertSame(10, $query->size);
    }

    public function test_explicit_legal_values_are_kept(): void
    {
        $ascending = ArchivePageQuery::from('archived', 'asc', '2', '1', 100);

        self::assertSame(ArchiveSort::Archived, $ascending->sort);
        self::assertSame(ArchiveDirection::Asc, $ascending->dir);
        self::assertSame(2, $ascending->page);
        self::assertSame(1, $ascending->size);

        $descending = ArchivePageQuery::from(null, 'desc', '3', '10', 100);

        self::assertSame(ArchiveDirection::Desc, $descending->dir);
        self::assertSame(3, $descending->page);
        self::assertSame(10, $descending->size);
    }

    public function test_largest_offset_that_fits_is_kept(): void
    {
        // Default size is 10. (922337203685477581 - 1) * 10 still fits in a PHP int. The next page does not.
        $query = ArchivePageQuery::from(null, null, '922337203685477581', null, 100);

        self::assertSame(922337203685477581, $query->page);
        self::assertSame(10, $query->size);
    }

    public function test_size_at_the_maximum_is_kept(): void
    {
        $query = ArchivePageQuery::from(null, null, null, '100', 100);

        self::assertSame(100, $query->size);
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejected_value_throws(?string $sort, ?string $dir, ?string $page, ?string $size): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        ArchivePageQuery::from($sort, $dir, $page, $size, 100);
    }

    /**
     * Each row puts the illegal string on the parameter it violates. The other arguments stay null.
     *
     * The offset row is a page that fits in a PHP int. With the default size 10, `(page - 1) * 10` does not.
     *
     * @return iterable<string, array{?string, ?string, ?string, ?string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'sort created_at' => ['created_at', null, null, null];
        yield 'dir ASC' => [null, 'ASC', null, null];
        yield 'page 0' => [null, null, '0', null];
        yield 'page -1' => [null, null, '-1', null];
        yield 'page +1' => [null, null, '+1', null];
        yield 'page 1.5' => [null, null, '1.5', null];
        yield 'page abc' => [null, null, 'abc', null];
        yield 'page 01' => [null, null, '01', null];
        yield 'page space 1' => [null, null, ' 1', null];
        yield 'page trailing newline' => [null, null, "1\n", null];
        yield 'page empty' => [null, null, '', null];
        yield 'size 0' => [null, null, null, '0'];
        yield 'size 101' => [null, null, null, '101'];
        yield 'page above PHP_INT_MAX' => [null, null, '9223372036854775808', null];
        yield 'offset does not fit' => [null, null, '922337203685477582', null];
    }
}
