<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive\InMemory;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Archive\Owner;

/**
 * Proves which stores made by the in-memory factory share rows.
 */
final class InMemoryArchiveStoreFactoryTest extends TestCase
{
    public function test_stores_made_for_one_owner_share_rows(): void
    {
        $factory = new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf());
        $archive = new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>');
        $factory->create(new Owner('ada@example.com'))->save($archive);

        $found = $factory->create(new Owner('ada@example.com'))->find('https://example.com/a');

        self::assertSame($archive, $found);
    }

    public function test_stores_made_for_two_owners_do_not(): void
    {
        $factory = new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf());
        $factory->create(new Owner('ada@example.com'))
            ->save(new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>'));

        $found = $factory->create(new Owner('grace@example.com'))->find('https://example.com/a');

        self::assertNull($found);
    }
}
