<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchiveId;
use Yumo\Eleanor\Archive\ArchiveListService;
use Yumo\Eleanor\Archive\ArchiveListServiceFactory;
use Yumo\Eleanor\Archive\ArchivePage;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchivePageSlice;
use Yumo\Eleanor\Archive\ArchiveSort;
use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\Sqlite\SqliteArchiveStoreFactory;
use Yumo\Eleanor\Archive\UrlPointer;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\FakeArchiveStore;

/**
 * Proves that a page read returns the slice and that a failed read is logged and rethrown.
 */
final class ArchiveListServiceTest extends TestCase
{
    private FakeArchiveStore $store;

    private RecordingLogger $logger;

    private ArchiveListService $service;

    protected function setUp(): void
    {
        $this->store = new FakeArchiveStore();
        $this->logger = new RecordingLogger();
        $this->service = new ArchiveListService($this->store, $this->logger);
    }

    public function test_page_returns_the_summaries_and_writes_no_line(): void
    {
        $items = [
            new ArchiveSummary('https://example.com/a', 'One', 'Excerpt', 'Site', 1, '2026-11-01T00:00:00Z'),
            new ArchiveSummary('https://example.com/b', 'Two', null, null, 2, '2026-11-01T00:00:01Z'),
        ];
        $this->store->page = new ArchivePageSlice($items, 7);
        $query = ArchivePageQuery::from('archived', 'asc', '2', '5', 100);

        $page = $this->service->page($query);

        self::assertEquals(new ArchivePage(ArchiveSort::Archived, ArchiveDirection::Asc, 2, 5, $items, 7), $page);
        self::assertSame([], $this->logger->records);
    }

    public function test_a_failed_read_logs_list_failed_and_rethrows(): void
    {
        $failure = new \RuntimeException('locked');
        $query = ArchivePageQuery::from('archived', 'desc', '3', '4', 100);
        $service = new ArchiveListService(new ThrowingPageStore($failure, '/data/ada.sqlite'), $this->logger);

        try {
            $service->page($query);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertSame(
                [[
                    'warning',
                    'archive.list_failed',
                    [
                        'sort' => 'archived',
                        'dir' => 'desc',
                        'page' => 3,
                        'size' => 4,
                        'store' => '/data/ada.sqlite',
                        'exception' => $failure,
                    ],
                ]],
                $this->logger->records,
            );
        }
    }

    public function test_a_failed_read_without_a_location_omits_store(): void
    {
        $failure = new \RuntimeException('locked');
        $query = ArchivePageQuery::from('archived', 'desc', '3', '4', 100);
        $service = new ArchiveListService(new ThrowingPageStore($failure, null), $this->logger);

        try {
            $service->page($query);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            self::assertSame(
                [[
                    'warning',
                    'archive.list_failed',
                    [
                        'sort' => 'archived',
                        'dir' => 'desc',
                        'page' => 3,
                        'size' => 4,
                        'exception' => $failure,
                    ],
                ]],
                $this->logger->records,
            );
        }
    }

    public function test_a_throwing_logger_still_rethrows_the_store_exception(): void
    {
        $failure = new \RuntimeException('locked');
        $service = new ArchiveListService(new ThrowingPageStore($failure, '/data/ada.sqlite'), new ThrowingLogger());

        try {
            $service->page($this->query());
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function test_a_missing_file_slice_does_not_log(): void
    {
        $page = $this->service->page($this->query());

        self::assertEquals(new ArchivePage(ArchiveSort::Archived, ArchiveDirection::Desc, 1, 10, [], 0), $page);
        self::assertSame([], $this->logger->records);
    }

    public function test_a_row_saved_for_ada_is_absent_from_bea(): void
    {
        $root = ArchiveDirectories::isolate();
        $stores = new SqliteArchiveStoreFactory($root . '/data');
        $factory = new ArchiveListServiceFactory($stores, new RecordingLogger());
        $ada = new Owner('ada@example.com');
        $bea = new Owner('bea@example.com');
        $stores->create($ada)->save(new Archive(
            'https://example.com/a',
            'Ada',
            null,
            null,
            '<p>A</p>',
            null,
            null,
        ));
        $query = ArchivePageQuery::from(null, null, null, null, 100);

        $adaPage = $factory->create($ada)->page($query);
        $beaPage = $factory->create($bea)->page($query);

        self::assertCount(1, $adaPage->items);
        self::assertInstanceOf(ArchiveSummary::class, $adaPage->items[0]);
        self::assertSame('https://example.com/a', $adaPage->items[0]->url);
        self::assertSame(1, $adaPage->total);
        self::assertSame([], $beaPage->items);
        self::assertSame(0, $beaPage->total);
    }

    private function query(): ArchivePageQuery
    {
        return ArchivePageQuery::from(null, null, null, null, 100);
    }
}

/**
 * Store whose page read throws, so a test can prove the service logs and rethrows.
 */
final class ThrowingPageStore implements ArchiveStore
{
    public function __construct(
        private \Throwable $error,
        private ?string $location,
    ) {
    }

    public function findSummary(UrlPointer $url): ?ArchiveSummary
    {
        throw new \LogicException('unused');
    }

    public function find(UrlPointer $url): ?Archive
    {
        throw new \LogicException('unused');
    }

    public function findSummaryById(ArchiveId $id): ?ArchiveSummary
    {
        throw $this->error;
    }

    public function findById(ArchiveId $id): ?Archive
    {
        throw $this->error;
    }

    public function findHtmlById(ArchiveId $id): ?string
    {
        throw $this->error;
    }

    public function findPage(ArchivePageQuery $query): ArchivePageSlice
    {
        throw $this->error;
    }

    public function save(Archive $archive): Archive
    {
        throw new \LogicException('unused');
    }

    public function location(): ?string
    {
        return $this->location;
    }
}
