<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Article;

/**
 * Proves an article is the purified body and carries no URL.
 */
final class ArticleTest extends TestCase
{
    public function test_holds_the_purified_body(): void
    {
        $article = new Article('Title', 'Excerpt', 'Site', '<p>Body</p>');

        self::assertSame('Title', $article->title);
        self::assertSame('Excerpt', $article->excerpt);
        self::assertSame('Site', $article->siteName);
        self::assertSame('<p>Body</p>', $article->html);

        $empty = new Article('', null, null, '');

        self::assertSame('', $empty->title);
        self::assertNull($empty->excerpt);
        self::assertNull($empty->siteName);
        self::assertSame('', $empty->html);
    }
}
