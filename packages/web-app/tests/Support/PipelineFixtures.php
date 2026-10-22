<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Archive\Article;

/**
 * Builds an article for tests that do not fetch a page.
 */
final class PipelineFixtures
{
    /**
     * Builds an article from plain strings. It has no URL and no warnings.
     */
    public static function article(
        string $title = 'Title',
        ?string $excerpt = null,
        ?string $siteName = null,
        string $html = '<p>Body</p>',
    ): Article {
        return new Article($title, $excerpt, $siteName, $html);
    }
}
