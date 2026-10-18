<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Response;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveResult;
use Yumo\Eleanor\Archive\ArchiveSummary;

/**
 * Proves that the archive success body copies only the URL and the title.
 */
final class ArchiveResponseTest extends TestCase
{
    public function test_copies_url_and_title_and_leaves_the_rest_of_the_result_out(): void
    {
        $result = new ArchiveResult(
            new ArchiveSummary('https://example.com/a', 'Title', 'Excerpt', 'Site'),
            ArchiveOutcome::Written,
        );

        $response = ArchiveResponse::fromArchiveResult($result);

        self::assertEquals(new ArchiveResponse('https://example.com/a', 'Title'), $response);
    }
}
