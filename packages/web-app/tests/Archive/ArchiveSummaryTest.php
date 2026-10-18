<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveSummary;

/**
 * Proves that a summary copies the light fields of an archive and leaves the HTML out.
 */
final class ArchiveSummaryTest extends TestCase
{
    public function test_copies_the_light_fields_of_an_archive(): void
    {
        $archive = new Archive(
            'https://example.com/a',
            'Title',
            'Excerpt',
            'Site',
            '<p>body</p>',
        );

        $summary = ArchiveSummary::fromArchive($archive);

        self::assertSame('https://example.com/a', $summary->url);
        self::assertSame('Title', $summary->title);
        self::assertSame('Excerpt', $summary->excerpt);
        self::assertSame('Site', $summary->siteName);
    }
}
