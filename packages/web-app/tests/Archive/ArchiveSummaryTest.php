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
            17,
            '2026-11-01T10:52:00Z',
        );

        $summary = ArchiveSummary::fromArchive($archive);

        self::assertSame('https://example.com/a', $summary->url);
        self::assertSame('Title', $summary->title);
        self::assertSame('Excerpt', $summary->excerpt);
        self::assertSame('Site', $summary->siteName);
    }

    public function test_copies_the_id(): void
    {
        $summary = ArchiveSummary::fromArchive(
            new Archive('https://example.com/a', 'Title', 'Excerpt', 'Site', '<p>body</p>', 17, '2026-11-01T10:52:00Z'),
        );

        self::assertSame(17, $summary->id);
    }

    public function test_rejects_an_archive_without_an_id(): void
    {
        $this->expectException(\LogicException::class);

        ArchiveSummary::fromArchive(
            new Archive('https://example.com/a', 'Title', null, null, '<p>body</p>', null, '2026-11-01T10:52:00Z'),
        );
    }

    public function test_copies_archived(): void
    {
        $summary = ArchiveSummary::fromArchive(
            new Archive(
                'https://example.com/a',
                'Title',
                'Excerpt',
                'Site',
                '<p>body</p>',
                17,
                '2026-11-01T10:52:00Z',
            ),
        );

        self::assertSame('2026-11-01T10:52:00Z', $summary->archived);
    }

    public function test_rejects_an_archive_without_archived(): void
    {
        $this->expectException(\LogicException::class);

        ArchiveSummary::fromArchive(
            new Archive('https://example.com/a', 'Title', null, null, '<p>body</p>', 17, null),
        );
    }
}
