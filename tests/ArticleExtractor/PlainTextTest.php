<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;

final class PlainTextTest extends TestCase
{
    public function test_from_untrusted_strips_tags_and_trims(): void
    {
        $text = PlainText::fromUntrusted('  <b>Hi</b>  ');

        self::assertSame('Hi', $text->raw());
        self::assertSame('Hi', (string) $text);
    }

    public function test_from_untrusted_keeps_empty_string(): void
    {
        $text = PlainText::fromUntrusted('  <b></b>  ');

        self::assertSame('', $text->raw());
    }

    public function test_html_escapes_markup_characters(): void
    {
        $text = PlainText::fromUntrusted('Tom & Jerry\'s "show"');

        self::assertSame('Tom & Jerry\'s "show"', $text->raw());
        self::assertSame('Tom &amp; Jerry&#039;s &quot;show&quot;', $text->html());
    }

    public function test_nullable_factory_returns_null_for_null_and_blank(): void
    {
        self::assertNull(PlainText::fromUntrustedNullable(null));
        self::assertNull(PlainText::fromUntrustedNullable(''));
        self::assertNull(PlainText::fromUntrustedNullable('   '));
        self::assertNull(PlainText::fromUntrustedNullable('<em></em>'));
    }

    public function test_nullable_factory_returns_text_when_something_remains(): void
    {
        $text = PlainText::fromUntrustedNullable('  <em>Excerpt</em>  ');

        self::assertInstanceOf(PlainText::class, $text);
        self::assertSame('Excerpt', $text->raw());
    }
}
