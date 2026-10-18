<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineWarning;
use Yumo\ArtcleReader\Orchestrator\PipelineWarningCode;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveStore;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves what the archive command stores, returns, and refuses.
 */
final class ArchiveServiceTest extends TestCase
{
    private const URL = 'https://example.com/a';

    private FakeArticleFetcher $fetcher;

    private InMemoryArchiveStore $store;

    private ArchiveService $service;

    protected function setUp(): void
    {
        $this->fetcher = new FakeArticleFetcher();
        $this->store = new InMemoryArchiveStore(new Owner('ada@example.com'), new InMemoryArchiveShelf());
        $this->service = new ArchiveService($this->store, $this->fetcher);
    }

    public function test_first_archive_fetches_saves_and_is_written(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

        $result = $this->service->archive(self::URL);
        $summary = $result->summary;

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
        self::assertSame(1, $this->fetcher->fetchCount());
        self::assertEquals(new ArchiveSummary(self::URL, 'Title', null, null), $summary);
        self::assertEquals(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'), $this->store->find(self::URL));
    }

    public function test_second_archive_is_already_stored_and_does_not_fetch(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success());
        $first = $this->service->archive(self::URL);

        $second = $this->service->archive(self::URL);

        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
        self::assertEquals($first->summary, $second->summary);
        self::assertSame(1, $this->fetcher->fetchCount());
    }

    public function test_archive_holds_the_five_fields_as_plain_values(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success('Title', 'Excerpt', 'Site', '<p>Body</p>'));

        $this->service->archive(self::URL);

        self::assertEquals(new Archive(self::URL, 'Title', 'Excerpt', 'Site', '<p>Body</p>'), $this->store->find(self::URL));
    }

    public function test_text_fields_are_stored_as_raw_text_not_escaped_html(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success('A & "B"', 'C & <b>D</b>', 'E & F'));

        $summary = $this->service->archive(self::URL)->summary;

        self::assertSame('A & "B"', $summary->title);
        self::assertSame('C & D', $summary->excerpt);
        self::assertSame('E & F', $summary->siteName);
    }

    public function test_excerpt_and_site_name_stay_null_when_missing(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

        $summary = $this->service->archive(self::URL)->summary;

        self::assertNull($summary->excerpt);
        self::assertNull($summary->siteName);
    }

    public function test_url_is_the_string_passed_in_not_the_source_url(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success(sourceUrl: 'https://example.com/other'));

        $summary = $this->service->archive(self::URL)->summary;

        self::assertSame(self::URL, $summary->url);
    }

    public function test_warning_is_left_out_of_the_archive(): void
    {
        $warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, 'Lossy conversion');
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success(warnings: [$warning]));

        $this->service->archive(self::URL);

        self::assertEquals(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'), $this->store->find(self::URL));
    }

    public function test_no_content_becomes_archive_exception_and_stores_nothing(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::noContent());

        try {
            $this->service->archive(self::URL);
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::SanitizedEmpty, $exception->failure);
            self::assertNull($this->store->find(self::URL));
        }
    }

    public function test_thrown_failure_becomes_archive_exception_with_that_cause(): void
    {
        $failure = new OrchestratorException(OrchestratorError::Fetch);
        $this->fetcher->willThrow(self::URL, $failure);

        try {
            $this->service->archive(self::URL);
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::Unexpected, $exception->failure);
            self::assertSame($failure, $exception->getPrevious());
            self::assertNull($this->store->find(self::URL));
        }
    }

    public function test_an_error_from_the_fetcher_passes_through(): void
    {
        $this->fetcher->willThrow(self::URL, new \Error('boom'));

        $this->expectException(\Error::class);

        $this->service->archive(self::URL);
    }

    public function test_a_runtime_exception_from_the_fetcher_passes_through(): void
    {
        $this->fetcher->willThrow(self::URL, new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        $this->service->archive(self::URL);
    }

    public function test_a_throwable_that_passes_through_stores_nothing(): void
    {
        $this->fetcher->willThrow(self::URL, new \RuntimeException('boom'));

        try {
            $this->service->archive(self::URL);
        } catch (\RuntimeException $exception) {
            self::assertNotInstanceOf(ArchiveException::class, $exception);
        }

        self::assertNull($this->store->find(self::URL));
    }

    public function test_a_failed_fetch_leaves_the_next_call_free_to_fetch(): void
    {
        $this->fetcher->willThrow(self::URL, new OrchestratorException(OrchestratorError::Fetch));

        foreach ([1, 2] as $attempt) {
            try {
                $this->service->archive(self::URL);
                self::fail('Expected an ArchiveException.');
            } catch (ArchiveException) {
                self::assertSame($attempt, $this->fetcher->fetchCount(self::URL));
            }
        }
    }

    public function test_trailing_slash_is_another_archive(): void
    {
        $this->fetcher->willReturn(self::URL, PipelineFixtures::success());
        $this->fetcher->willReturn(self::URL . '/', PipelineFixtures::success());
        $this->service->archive(self::URL);

        $result = $this->service->archive(self::URL . '/');

        self::assertSame(ArchiveOutcome::Written, $result->outcome);
        self::assertSame(2, $this->fetcher->fetchCount());
    }
}
