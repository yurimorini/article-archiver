<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Response;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Yumo\Eleanor\Api\Response\ArchiveListDocuments;
use Yumo\Eleanor\Api\Response\ArchiveListResponse;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchivePage;
use Yumo\Eleanor\Archive\ArchiveSort;
use Yumo\Eleanor\Archive\ArchiveSummary;

/**
 * Proves that the list document keeps each summary and adds next only when another page exists.
 */
final class ArchiveListDocumentsTest extends TestCase
{
    public function test_next_is_the_path_the_router_returns_when_another_page_exists(): void
    {
        $urls = new RecordingUrlGenerator('/api/archives?sort=archived&dir=desc&page=2&size=10');
        $page = new ArchivePage(
            ArchiveSort::Archived,
            ArchiveDirection::Desc,
            1,
            10,
            [new ArchiveSummary('https://example.com/a', 'Title', 'Excerpt', 'Example', 17, '2026-11-01T10:52:00Z')],
            11,
        );

        $response = new ArchiveListDocuments($urls)->response($page);

        self::assertSame([
            [
                'list_archives',
                ['sort' => 'archived', 'dir' => 'desc', 'page' => 2, 'size' => 10],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ],
        ], $urls->calls);
        self::assertEquals(new ArchiveListResponse(
            'archived',
            'desc',
            1,
            10,
            [new ArchiveSummary('https://example.com/a', 'Title', 'Excerpt', 'Example', 17, '2026-11-01T10:52:00Z')],
            11,
            '/api/archives?sort=archived&dir=desc&page=2&size=10',
        ), $response);
    }

    public function test_next_is_null_when_the_page_covers_the_total(): void
    {
        $urls = new RecordingUrlGenerator('/api/archives?sort=archived&dir=desc&page=2&size=10');
        $page = new ArchivePage(
            ArchiveSort::Archived,
            ArchiveDirection::Desc,
            1,
            10,
            [new ArchiveSummary('https://example.com/a', 'Title', 'Excerpt', 'Example', 17, '2026-11-01T10:52:00Z')],
            10,
        );

        $response = new ArchiveListDocuments($urls)->response($page);

        self::assertSame([], $urls->calls);
        self::assertNull($response->next);
    }

    public function test_null_site_name_stays_null(): void
    {
        $urls = new RecordingUrlGenerator('/unused');
        $page = new ArchivePage(
            ArchiveSort::Archived,
            ArchiveDirection::Desc,
            1,
            10,
            [new ArchiveSummary('https://example.com/a', 'Title', null, null, 4, '2026-11-01T10:52:00Z')],
            1,
        );

        $response = new ArchiveListDocuments($urls)->response($page);

        self::assertSame($page->items[0], $response->items[0]);
    }
}

/**
 * Returns one fixed path and records every generate call.
 */
final class RecordingUrlGenerator implements UrlGeneratorInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: int}> */
    public array $calls = [];

    private RequestContext $context;

    public function __construct(private readonly string $next)
    {
        $this->context = new RequestContext();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $this->calls[] = [$name, $parameters, $referenceType];

        return $this->next;
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
