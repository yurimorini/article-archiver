<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\ArchiveId;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Proves that an archive id accepts one positive integer that fits in a PHP int.
 */
final class ArchiveIdTest extends TestCase
{
    public function test_a_positive_integer_is_kept(): void
    {
        self::assertSame(17, ArchiveId::from('17')->value);
    }

    public function test_php_int_max_is_kept(): void
    {
        self::assertSame(PHP_INT_MAX, ArchiveId::from((string) PHP_INT_MAX)->value);
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejected_segment_throws(string $segment): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        ArchiveId::from($segment);
    }

    /**
     * Each row is one path segment that is not a positive integer inside a PHP int.
     *
     * The last row is the digit string of `PHP_INT_MAX` plus one. That value does not fit.
     *
     * @return iterable<string, array{string}>
     */
    public static function rejectedProvider(): iterable
    {
        // PHP_INT_MAX is one less than a power of two, so its last digit is 7. Replacing that digit with 8 is one above the max.
        $above = (string) PHP_INT_MAX;
        $above[-1] = '8';

        yield 'abc' => ['abc'];
        yield '0' => ['0'];
        yield '-3' => ['-3'];
        yield '1.5' => ['1.5'];
        yield '01' => ['01'];
        yield '+1' => ['+1'];
        yield 'leading space' => [' 1'];
        yield 'empty' => [''];
        yield 'one above PHP_INT_MAX' => [$above];
    }
}
