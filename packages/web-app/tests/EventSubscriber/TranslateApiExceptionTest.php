<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\EventSubscriber;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yumo\Eleanor\Api\Error\ErrorTranslator;
use Yumo\Eleanor\Api\Response\JsonBodySerializer;
use Yumo\Eleanor\Api\Response\ReplyResponder;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\EventSubscriber\TranslateApiException;
use Yumo\Eleanor\Request\EnrichedRequest;

/**
 * Proves that the subscriber answers failures under /api, logs server and site failures, and leaves other paths alone.
 */
final class TranslateApiExceptionTest extends TestCase
{
    private TestHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
    }

    public function test_subscribes_to_the_exception_event_before_the_framework(): void
    {
        self::assertSame(
            [KernelEvents::EXCEPTION => ['onException', 10]],
            TranslateApiException::getSubscribedEvents(),
        );
    }

    public function test_leaves_a_path_outside_api_untouched(): void
    {
        $event = $this->exceptionEvent('/apiary', new \Error('boom'));

        $this->subscriber()->onException($event);

        self::assertFalse($event->hasResponse());
        self::assertSame([], $this->handler->getRecords());
    }

    public function test_the_api_root_is_answered(): void
    {
        $event = $this->exceptionEvent('/api', new \Error('boom'));

        $this->subscriber()->onException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function test_a_502_is_logged_with_its_cause(): void
    {
        $failure = new ArchiveException(ArchiveFailure::Transport, new \RuntimeException('connect refused'));
        $event = $this->exceptionEvent('/api/archives', $failure);
        $event->getRequest()->attributes->set(EnrichedRequest::class, new EnrichedRequest('req-1', null, null));

        $this->subscriber()->onException($event);

        self::assertSame(502, $event->getResponse()?->getStatusCode());
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('http.request.failed', $records[0]->message);
        self::assertSame($failure, $records[0]->context['exception']);
        self::assertSame('transport', $records[0]->context['code']);
        self::assertSame('req-1', $records[0]->context['request_id']);
    }

    public function test_a_500_is_logged(): void
    {
        $error = new \Error('boom');
        $event = $this->exceptionEvent('/api/archives', $error);

        $this->subscriber()->onException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame($error, $records[0]->context['exception']);
        self::assertSame('unexpected', $records[0]->context['code']);
        self::assertArrayNotHasKey('request_id', $records[0]->context);
    }

    public function test_a_422_writes_no_error_line(): void
    {
        $event = $this->exceptionEvent('/api/archives', new ArchiveException(ArchiveFailure::ExtractNoContent));

        $this->subscriber()->onException($event);

        self::assertSame(422, $event->getResponse()?->getStatusCode());
        self::assertSame([], $this->handler->getRecords());
    }

    public function test_a_405_keeps_the_allow_header(): void
    {
        $event = $this->exceptionEvent('/api/archives', new MethodNotAllowedHttpException(['POST']));

        $this->subscriber()->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->headers->get('Allow'));
        self::assertSame([], $this->handler->getRecords());
    }

    public function test_a_failing_logger_does_not_change_the_response(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('disk full'));
        $event = $this->exceptionEvent('/api/archives', new \Error('boom'));

        $this->subscriber($logger)->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            '{"code":"unexpected","message":"Something went wrong on the server."}',
            $response->getContent(),
        );
    }

    private function exceptionEvent(string $path, \Throwable $error): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $error,
        );
    }

    private function subscriber(?LoggerInterface $logger = null): TranslateApiException
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        return new TranslateApiException(
            new ErrorTranslator(),
            new ReplyResponder(new JsonBodySerializer($serializer)),
            $logger ?? new Logger('application', [$this->handler]),
        );
    }
}
