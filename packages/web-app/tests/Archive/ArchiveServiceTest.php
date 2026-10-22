<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Article;
use Yumo\Eleanor\Archive\ArticleFetcher;
use Yumo\Eleanor\Archive\Sqlite\DirectoryException;
use Yumo\Eleanor\Archive\UrlPointer;
use Yumo\Eleanor\Tests\Support\FakeArchiveLock;
use Yumo\Eleanor\Tests\Support\FakeArchiveStore;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves what the archive command stores, returns, and refuses.
 */
final class ArchiveServiceTest extends TestCase
{
    private const URL = 'https://example.com/a';

    private FakeArticleFetcher $fetcher;

    private FakeArchiveStore $store;

    private FakeArchiveLock $lock;

    private ArchiveService $service;

    private RecordingLogger $logger;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $log;

    protected function setUp(): void
    {
        $this->log = new \ArrayObject();
        $this->fetcher = new FakeArticleFetcher();
        $this->store = new FakeArchiveStore($this->log);
        $this->lock = new FakeArchiveLock($this->log);
        $this->logger = new RecordingLogger();
        $this->service = $this->serviceWith($this->lock);
    }

    public function test_first_archive_fetches_saves_and_is_written(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $result = $this->service->archive(new UrlPointer(self::URL));
        $summary = $result->summary;

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
        self::assertSame(1, $this->fetcher->fetchCount());
        self::assertEquals(new ArchiveSummary(self::URL, 'Title', null, null), $summary);
        self::assertEquals(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'), $this->store->find(new UrlPointer(self::URL)));
    }

    public function test_second_archive_is_already_stored_and_does_not_fetch(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $first = $this->service->archive(new UrlPointer(self::URL));

        $second = $this->service->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
        self::assertEquals($first->summary, $second->summary);
        self::assertSame(1, $this->fetcher->fetchCount());
    }

    public function test_archive_holds_the_five_fields_as_plain_values(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article('Title', 'Excerpt', 'Site', '<p>Body</p>'));

        $this->service->archive(new UrlPointer(self::URL));

        self::assertEquals(new Archive(self::URL, 'Title', 'Excerpt', 'Site', '<p>Body</p>'), $this->store->find(new UrlPointer(self::URL)));
    }

    public function test_text_fields_are_stored_as_the_article_text(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article('A & "B"', 'C & <b>D</b>', 'E & F'));

        $summary = $this->service->archive(new UrlPointer(self::URL))->summary;

        self::assertSame('A & "B"', $summary->title);
        self::assertSame('C & <b>D</b>', $summary->excerpt);
        self::assertSame('E & F', $summary->siteName);
    }

    public function test_excerpt_and_site_name_stay_null_when_missing(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $summary = $this->service->archive(new UrlPointer(self::URL))->summary;

        self::assertNull($summary->excerpt);
        self::assertNull($summary->siteName);
    }

    public function test_url_is_the_string_passed_in_not_the_source_url(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $summary = $this->service->archive(new UrlPointer(self::URL))->summary;

        self::assertSame(self::URL, $summary->url);
    }

    public function test_no_content_becomes_archive_exception_and_stores_nothing(): void
    {
        $this->fetcher->willThrow(self::URL, new ArchiveException(ArchiveFailure::ExtractNoContent));

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::ExtractNoContent, $exception->failure);
            self::assertNull($this->store->find(new UrlPointer(self::URL)));
        }
    }

    public function test_thrown_failure_becomes_archive_exception_with_that_cause(): void
    {
        $cause = new \RuntimeException('boom');
        $failure = new ArchiveException(ArchiveFailure::Transport, $cause);
        $this->fetcher->willThrow(self::URL, $failure);

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::Transport, $exception->failure);
            self::assertSame($cause, $exception->getPrevious());
            self::assertNull($this->store->find(new UrlPointer(self::URL)));
        }
    }

    public function test_an_unregistered_url_is_url_rejected(): void
    {
        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::UrlRejected, $exception->failure);
            self::assertNull($this->store->find(new UrlPointer(self::URL)));
        }
    }

    public function test_a_failed_fetch_leaves_the_next_call_free_to_fetch(): void
    {
        $this->fetcher->willThrow(self::URL, new ArchiveException(ArchiveFailure::Transport));

        foreach ([1, 2] as $attempt) {
            try {
                $this->service->archive(new UrlPointer(self::URL));
                self::fail('Expected an ArchiveException.');
            } catch (ArchiveException) {
                self::assertSame($attempt, $this->fetcher->fetchCount(self::URL));
            }
        }
    }

    public function test_trailing_slash_is_another_archive(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $this->fetcher->willReturn(self::URL . '/', PipelineFixtures::article());
        $this->service->archive(new UrlPointer(self::URL));

        $result = $this->service->archive(new UrlPointer(self::URL . '/'));

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
        self::assertSame(2, $this->fetcher->fetchCount());
    }

    public function test_leading_and_trailing_nul_are_trimmed_before_lock_and_store(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $this->service->archive(new UrlPointer("\0" . self::URL . "\0"));

        self::assertSame([self::URL], $this->lock->urls);
        self::assertSame([self::URL], $this->store->summaryLookups);
        self::assertSame([self::URL], $this->store->savedUrls);
        self::assertSame(1, $this->fetcher->fetchCount(self::URL));
    }

    public function test_a_nul_in_the_middle_stays_part_of_the_url(): void
    {
        $url = "https://example.com/\0a";
        $this->fetcher->willReturn($url, PipelineFixtures::article());

        $this->service->archive(new UrlPointer($url));

        self::assertSame([$url], $this->store->savedUrls);
        self::assertNull($this->store->findSummary(new UrlPointer(self::URL)));
    }

    public function test_surrounding_spaces_stay_part_of_the_url(): void
    {
        $url = ' ' . self::URL;
        $this->fetcher->willReturn($url, PipelineFixtures::article());

        $this->service->archive(new UrlPointer($url));

        self::assertSame([$url], $this->store->savedUrls);
        self::assertSame([$url], $this->lock->urls);
    }

    public function test_lookup_fetch_and_save_run_inside_the_lock(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $service = new ArchiveService($this->store, $this->loggingFetcher(), $this->lock, $this->logger);

        $service->archive(new UrlPointer(self::URL));

        self::assertSame(['enter', 'find', 'fetch', 'save', 'leave'], $this->log->getArrayCopy());
    }

    public function test_waiter_returns_already_stored_without_a_fetch(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $this->service->archive(new UrlPointer(self::URL));
        $fetchesBefore = $this->fetcher->fetchCount();

        $result = $this->service->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::AlreadyStored, $result->outcome);
        self::assertSame($fetchesBefore, $this->fetcher->fetchCount());
    }

    public function test_lock_is_released_when_the_fetch_throws(): void
    {
        $this->fetcher->willThrow(self::URL, new ArchiveException(ArchiveFailure::Transport));
        $service = new ArchiveService($this->store, $this->loggingFetcher(), $this->lock, $this->logger);

        try {
            $service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException) {
            self::assertSame('leave', $this->log->getArrayCopy()[count($this->log) - 1]);
            self::assertSame([], $this->store->savedUrls);
        }
    }

    public function test_a_lock_that_is_not_taken_still_archives(): void
    {
        $lock = new FakeArchiveLock($this->log, holds: false);
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $result = $this->serviceWith($lock)->archive(new UrlPointer(self::URL));

        self::assertFalse($lock->held);
        self::assertSame(ArchiveOutcome::Written, $result->outcome);
    }

    public function test_an_archive_exception_from_save_is_rethrown(): void
    {
        $failure = new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('locked'));
        $this->store->location = '/data/ada.sqlite';
        $this->store->saveFailure = $failure;
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame($failure, $exception);
            self::assertSame(
                ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written', 'store' => '/data/ada.sqlite']],
                $this->logger->records[2],
            );
        }
    }

    public function test_a_pdo_exception_from_save_passes_through(): void
    {
        $this->store->saveFailure = new \PDOException('locked');
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected a PDOException.');
        } catch (\PDOException) {
            self::assertSame([
                ['info', 'archive.start', ['url' => self::URL]],
                ['info', 'archive.fetch', ['url' => self::URL]],
            ], $this->logger->records);
        }
    }

    public function test_a_directory_exception_from_save_passes_through(): void
    {
        $this->store->saveFailure = new DirectoryException('The data directory could not be created.');
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected a DirectoryException.');
        } catch (DirectoryException) {
            self::assertSame([
                ['info', 'archive.start', ['url' => self::URL]],
                ['info', 'archive.fetch', ['url' => self::URL]],
            ], $this->logger->records);
        }
    }

    public function test_a_pdo_exception_from_find_summary_is_not_wrapped(): void
    {
        $this->store->findSummaryFailure = new \PDOException('locked');

        $this->expectException(\PDOException::class);

        $this->service->archive(new UrlPointer(self::URL));
    }

    public function test_a_directory_exception_from_find_summary_is_not_wrapped(): void
    {
        $this->store->findSummaryFailure = new DirectoryException('The data directory could not be created.');

        $this->expectException(DirectoryException::class);

        $this->service->archive(new UrlPointer(self::URL));
    }

    public function test_a_miss_logs_start_fetch_and_written(): void
    {
        $this->store->location = '/data/ada.sqlite';
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $result = $this->service->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
        self::assertSame([
            ['info', 'archive.start', ['url' => self::URL]],
            ['info', 'archive.fetch', ['url' => self::URL]],
            ['info', 'archive.written', ['url' => self::URL, 'store' => '/data/ada.sqlite']],
        ], $this->logger->records);
    }

    public function test_a_miss_without_a_location_omits_store(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        $this->service->archive(new UrlPointer(self::URL));

        self::assertSame(
            ['info', 'archive.written', ['url' => self::URL]],
            $this->logger->records[2],
        );
    }

    public function test_a_hit_logs_start_and_already_stored(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $this->service->archive(new UrlPointer(self::URL));
        $this->logger->records = [];

        $result = $this->service->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::AlreadyStored, $result->outcome);
        self::assertSame([
            ['info', 'archive.start', ['url' => self::URL]],
            ['info', 'archive.already_stored', ['url' => self::URL]],
        ], $this->logger->records);
        self::assertSame(1, $this->fetcher->fetchCount());
    }

    public function test_a_mapped_fetch_failure_logs_failed_without_store(): void
    {
        $this->store->location = '/data/ada.sqlite';
        $this->fetcher->willThrow(self::URL, new ArchiveException(ArchiveFailure::Transport));

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException) {
            self::assertSame([
                ['info', 'archive.start', ['url' => self::URL]],
                ['info', 'archive.fetch', ['url' => self::URL]],
                ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'transport']],
            ], $this->logger->records);
        }
    }

    public function test_an_empty_page_logs_failed_without_store(): void
    {
        $this->store->location = '/data/ada.sqlite';
        $this->fetcher->willThrow(self::URL, new ArchiveException(ArchiveFailure::SanitizedEmpty));

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException) {
            self::assertSame(
                ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'sanitized_empty']],
                $this->logger->records[2],
            );
        }
    }

    public function test_a_save_failure_without_a_location_omits_store(): void
    {
        $this->store->saveFailure = new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('locked'));
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException) {
            self::assertSame(
                ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written']],
                $this->logger->records[2],
            );
        }
    }

    public function test_a_pdo_exception_from_find_summary_logs_start_only(): void
    {
        $this->store->findSummaryFailure = new \PDOException('locked');

        try {
            $this->service->archive(new UrlPointer(self::URL));
            self::fail('Expected a PDOException.');
        } catch (\PDOException) {
            self::assertSame([
                ['info', 'archive.start', ['url' => self::URL]],
            ], $this->logger->records);
        }
    }

    public function test_a_throwing_logger_still_returns_written(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $service = new ArchiveService($this->store, $this->fetcher, $this->lock, new ThrowingLogger());

        $result = $service->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
    }

    public function test_a_throwing_logger_on_save_failure_still_throws_not_written(): void
    {
        $failure = new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('locked'));
        $this->store->saveFailure = $failure;
        $this->fetcher->willReturn(self::URL, PipelineFixtures::article());
        $service = new ArchiveService($this->store, $this->fetcher, $this->lock, new ThrowingLogger());

        try {
            $service->archive(new UrlPointer(self::URL));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame($failure, $exception);
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
        }
    }

    private function serviceWith(FakeArchiveLock $lock): ArchiveService
    {
        return new ArchiveService($this->store, $this->fetcher, $lock, $this->logger);
    }

    /**
     * Wraps the fake fetcher so that each fetch writes `fetch` to the shared log.
     */
    private function loggingFetcher(): ArticleFetcher
    {
        return new class ($this->fetcher, $this->log) implements ArticleFetcher {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private FakeArticleFetcher $inner, private \ArrayObject $log)
            {
            }

            public function fetch(UrlPointer $url): Article
            {
                $this->log->append('fetch');

                return $this->inner->fetch($url);
            }
        };
    }
}

/**
 * Records each log call as level, message, and context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}

/**
 * Throws on every log call so a test can prove logging is not the outcome.
 */
final class ThrowingLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('disk full');
    }
}
