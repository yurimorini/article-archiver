<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
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
}
