<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\Sqlite\SqliteArchiveStoreFactory;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves that services made for one owner see the same archives.
 */
final class ArchiveServiceFactoryTest extends TestCase
{
    public function test_second_service_for_one_owner_sees_the_first_archive(): void
    {
        $fetcher = new FakeArticleFetcher();
        $fetcher->willReturn('https://example.com/a', PipelineFixtures::success());
        $factory = new ArchiveServiceFactory($fetcher, new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()));
        $owner = new Owner('ada@example.com');

        $first = $factory->create($owner)->archive('https://example.com/a');
        $second = $factory->create($owner)->archive('https://example.com/a');

        self::assertSame(ArchiveOutcome::Written, $first->outcome);
        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
        self::assertSame(1, $fetcher->fetchCount());
    }

    public function test_second_owner_gets_written_for_a_url_the_first_owner_stored(): void
    {
        $root = ArchiveDirectories::isolate();
        $fetcher = new FakeArticleFetcher();
        $fetcher->willReturn('https://example.com/a', PipelineFixtures::success());
        $factory = new ArchiveServiceFactory($fetcher, new SqliteArchiveStoreFactory($root . '/data'));

        $first = $factory->create(new Owner('ada@example.com'))->archive('https://example.com/a');
        $second = $factory->create(new Owner('grace@example.com'))->archive('https://example.com/a');

        self::assertSame(ArchiveOutcome::Written, $first->outcome);
        self::assertSame(ArchiveOutcome::Written, $second->outcome);
        self::assertSame(2, $fetcher->fetchCount());
    }
}
