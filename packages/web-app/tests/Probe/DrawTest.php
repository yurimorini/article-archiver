<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\Draw;

/**
 * Proves that a draw keeps a seeded sample and drops blanks.
 */
final class DrawTest extends TestCase
{
    public function test_same_seed_returns_the_same_draw_and_drops_blanks(): void
    {
        $lines = [" https://example.com/a\n", '', 'https://example.com/b', '  '];

        self::assertSame(Draw::take($lines, 7, 10), Draw::take($lines, 7, 10));
        self::assertContains('https://example.com/b', Draw::take($lines, 7, 10));
        self::assertNotContains('', Draw::take($lines, 7, 10));
    }

    public function test_caps_the_draw_at_the_sample_and_rejects_a_size_past_ten(): void
    {
        $lines = array_map(static fn (int $i): string => 'https://example.com/' . $i, range(1, 15));

        self::assertCount(10, Draw::take($lines, 1, 10));
        $this->expectException(\InvalidArgumentException::class);
        Draw::take($lines, 1, 11);
    }
}
