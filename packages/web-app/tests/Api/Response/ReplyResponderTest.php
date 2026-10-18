<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Response;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Api\Response\JsonBodySerializer;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Api\Response\ReplyResponder;

/**
 * Proves that the responder turns a reply into a JSON response with its status and headers.
 */
final class ReplyResponderTest extends TestCase
{
    public function test_answers_with_the_status_json_content_type_and_serialized_body(): void
    {
        $body = new ArchiveResponse('https://example.com/a', 'Title');

        $response = $this->responder()->respond(new Reply(201, $body));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('content-type'));
        self::assertSame('{"url":"https://example.com/a","title":"Title"}', $response->getContent());
    }

    public function test_adds_the_given_headers(): void
    {
        $reply = new Reply(405, new ArchiveResponse('https://example.com', 'T'));

        $response = $this->responder()->respond($reply, ['Allow' => 'POST']);

        self::assertSame('POST', $response->headers->get('allow'));
    }

    private function responder(): ReplyResponder
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        return new ReplyResponder(new JsonBodySerializer($serializer));
    }
}
