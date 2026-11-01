<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Response;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yumo\Eleanor\Api\Response\ArchiveDetailResponse;
use Yumo\Eleanor\Api\Response\JsonBodySerializer;
use Yumo\Eleanor\Archive\Archive;

/**
 * Proves that the detail document copies one stored archive and writes the HTML last.
 */
final class ArchiveDetailResponseTest extends TestCase
{
    public function test_copies_the_stored_archive(): void
    {
        $document = ArchiveDetailResponse::fromArchive(new Archive(
            'https://example.com/a',
            'Title',
            'Excerpt',
            'Site',
            '<p>body</p>',
            1,
            '2026-11-01T00:00:00Z',
        ));

        self::assertEquals(new ArchiveDetailResponse(
            'https://example.com/a',
            'Title',
            'Excerpt',
            'Site',
            1,
            '2026-11-01T00:00:00Z',
            '<p>body</p>',
        ), $document);
    }

    public function test_rejects_an_archive_without_an_id(): void
    {
        $this->expectException(\LogicException::class);

        ArchiveDetailResponse::fromArchive(new Archive(
            'https://example.com/a',
            'Title',
            null,
            null,
            '<p>body</p>',
            null,
            '2026-11-01T00:00:00Z',
        ));
    }

    public function test_rejects_an_archive_without_an_insert_time(): void
    {
        $this->expectException(\LogicException::class);

        ArchiveDetailResponse::fromArchive(new Archive(
            'https://example.com/a',
            'Title',
            null,
            null,
            '<p>body</p>',
            1,
            null,
        ));
    }

    public function test_writes_the_document_with_html_last_and_keeps_the_markup(): void
    {
        $document = ArchiveDetailResponse::fromArchive(new Archive(
            'https://example.com/a',
            'Title',
            null,
            null,
            '<p>say "hi" <b>x</b></p>',
            1,
            '2026-11-01T00:00:00Z',
        ));

        $json = $this->serializer()->serialize($document);

        self::assertSame(
            '{"url":"https://example.com/a","title":"Title","excerpt":null,"siteName":null,"id":1,"archived":"2026-11-01T00:00:00Z","html":"<p>say \\"hi\\" <b>x</b></p>"}',
            $json,
        );
        self::assertSame('<p>say "hi" <b>x</b></p>', json_decode($json, true, flags: JSON_THROW_ON_ERROR)['html']);
    }

    private function serializer(): JsonBodySerializer
    {
        return new JsonBodySerializer(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
    }
}
