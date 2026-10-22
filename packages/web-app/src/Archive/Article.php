<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The purified body of one page.
 *
 * `$article = new Article('Title', null, null, '<p>Body</p>');`
 */
#[Exclude]
final readonly class Article
{
    /**
     * Creates the purified body. It has no URL.
     */
    public function __construct(
        /** The article title. It is empty when the page has none. */
        public string $title,
        /** The short description, or null when the page has none. */
        public ?string $excerpt,
        /** The name of the site, or null when the page has none. */
        public ?string $siteName,
        /** The purified article HTML. */
        public string $html,
    ) {
    }
}
