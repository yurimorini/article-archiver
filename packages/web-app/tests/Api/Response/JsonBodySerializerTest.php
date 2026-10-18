<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Response;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Api\Response\ErrorResponse;
use Yumo\Eleanor\Api\Response\JsonBodySerializer;

/**
 * Proves that the one serializer writes both body types as exact JSON.
 */
final class JsonBodySerializerTest extends TestCase
{
    public function test_writes_an_archive_body_without_escaping_slashes_or_unicode(): void
    {
        $json = $this->serializer()->serialize(new ArchiveResponse('https://example.com/a/b', 'Café'));

        self::assertSame('{"url":"https://example.com/a/b","title":"Café"}', $json);
    }

    public function test_quotes_and_backslashes_survive_a_round_trip(): void
    {
        $title = 'He said "hi" \ and left';

        $json = $this->serializer()->serialize(new ArchiveResponse('https://example.com', $title));

        self::assertSame($title, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['title']);
    }

    public function test_writes_an_error_body(): void
    {
        $json = $this->serializer()->serialize(new ErrorResponse('not_found', 'The path does not exist.'));

        self::assertSame('{"code":"not_found","message":"The path does not exist."}', $json);
    }

    private function serializer(): JsonBodySerializer
    {
        return new JsonBodySerializer(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
    }
}
