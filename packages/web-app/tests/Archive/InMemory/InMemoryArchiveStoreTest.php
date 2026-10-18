<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive\InMemory;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveStore;

/**
 * Proves what the in-memory store keeps for one owner.
 */
final class InMemoryArchiveStoreTest extends TestCase
{
    public function test_unknown_url_is_absent(): void
    {
        $store = new InMemoryArchiveStore(new Owner('ada@example.com'), new InMemoryArchiveShelf());

        self::assertNull($store->find('https://example.com/a'));
    }

    public function test_save_then_find_returns_the_same_archive(): void
    {
        $archive = $this->archive('https://example.com/a');
        $store = new InMemoryArchiveStore(new Owner('ada@example.com'), new InMemoryArchiveShelf());

        $store->save($archive);

        self::assertSame($archive, $store->find('https://example.com/a'));
    }

    public function test_two_stores_for_one_owner_share_rows(): void
    {
        $shelf = new InMemoryArchiveShelf();
        $archive = $this->archive('https://example.com/a');
        new InMemoryArchiveStore(new Owner('ada@example.com'), $shelf)->save($archive);

        $second = new InMemoryArchiveStore(new Owner('ada@example.com'), $shelf);

        self::assertSame($archive, $second->find('https://example.com/a'));
    }

    public function test_a_store_for_another_owner_does_not_see_the_archive(): void
    {
        $shelf = new InMemoryArchiveShelf();
        new InMemoryArchiveStore(new Owner('ada@example.com'), $shelf)->save($this->archive('https://example.com/a'));

        $other = new InMemoryArchiveStore(new Owner('grace@example.com'), $shelf);

        self::assertNull($other->find('https://example.com/a'));
    }

    public function test_find_summary_returns_the_light_fields(): void
    {
        $url = 'https://example.com/a';
        $title = 'Title';
        $excerpt = 'Excerpt';
        $siteName = 'Site';
        $store = new InMemoryArchiveStore(new Owner('ada@example.com'), new InMemoryArchiveShelf());
        $store->save(new Archive($url, $title, $excerpt, $siteName, '<p>x</p>'));

        $summary = $store->findSummary($url);
        $stored = $store->find($url);

        self::assertEquals(new ArchiveSummary($url, $title, $excerpt, $siteName), $summary);
        self::assertNotNull($stored);
        self::assertSame('<p>x</p>', $stored->html);
    }

    private function archive(string $url): Archive
    {
        return new Archive($url, 'Title', null, null, '<p>Body</p>');
    }
}
