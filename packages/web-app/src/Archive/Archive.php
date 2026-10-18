<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The stored copy of one fetched page, named by the URL pointer it was requested with.
 *
 * Every text field is a plain string. The HTML is the purified article.
 */
#[Exclude]
final readonly class Archive
{
    public function __construct(
        /** The URL string the client sent. It names the archive. */
        public string $url,
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
