<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Api\Response\JsonBodySerializer;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Api\Response\ReplyResponder;
use Yumo\Eleanor\EventSubscriber\WriteReply;

/**
 * Proves that the subscriber turns a controller's reply into a response and ignores other results.
 */
final class WriteReplyTest extends TestCase
{
    public function test_subscribes_to_the_view_event(): void
    {
        self::assertSame([KernelEvents::VIEW => 'onView'], WriteReply::getSubscribedEvents());
    }

    public function test_a_reply_becomes_a_response_with_its_status_and_body(): void
    {
        $event = $this->viewEvent(new Reply(201, new ArchiveResponse('https://example.com/a', 'T')));

        $this->subscriber()->onView($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"url":"https://example.com/a","title":"T"}', $response->getContent());
    }

    public function test_another_result_is_left_alone(): void
    {
        $event = $this->viewEvent('not a reply');

        $this->subscriber()->onView($event);

        self::assertFalse($event->hasResponse());
    }

    private function viewEvent(mixed $result): ViewEvent
    {
        return new ViewEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $result,
        );
    }

    private function subscriber(): WriteReply
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        return new WriteReply(new ReplyResponder(new JsonBodySerializer($serializer)));
    }
}
