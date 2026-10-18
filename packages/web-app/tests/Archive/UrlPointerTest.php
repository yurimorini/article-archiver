<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Proves that a URL pointer normalizes nul bytes at the ends and exposes the stored URL via toString().
 */
final class UrlPointerTest extends TestCase
{
    public function test_leading_and_trailing_nul_bytes_are_removed(): void
    {
        self::assertSame('https://example.com/a', (new UrlPointer("\0https://example.com/a\0"))->toString());
    }

    public function test_a_nul_in_the_middle_stays(): void
    {
        self::assertSame("https://example.com/\0a", (new UrlPointer("https://example.com/\0a"))->toString());
    }

    public function test_a_surrounding_space_stays(): void
    {
        self::assertSame(' https://example.com/a', (new UrlPointer(' https://example.com/a'))->toString());
    }

    public function test_a_trailing_slash_stays(): void
    {
        self::assertNotSame(
            (new UrlPointer('https://example.com/a'))->toString(),
            (new UrlPointer('https://example.com/a/'))->toString(),
        );
    }

    public function test_only_nul_bytes_become_an_empty_string(): void
    {
        self::assertSame('', (new UrlPointer("\0\0"))->toString());
    }

    public function test_a_string_cast_is_an_error(): void
    {
        $this->expectException(\Error::class);

        $unused = (string) new UrlPointer('https://example.com/a');
        self::assertNotSame('', $unused);
    }
}
