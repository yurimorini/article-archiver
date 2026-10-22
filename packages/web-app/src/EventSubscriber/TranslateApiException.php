<?php

declare(strict_types=1);

namespace Yumo\Eleanor\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Yumo\Eleanor\Api\Error\ErrorTranslator;
use Yumo\Eleanor\Api\Error\RequestFailure;
use Yumo\Eleanor\Api\Response\ErrorResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Api\Response\ReplyResponder;
use Yumo\Eleanor\Request\EnrichedRequest;

/**
 * Answers every failure under `/api` with the JSON error body, and logs server and site failures.
 *
 * It runs before the framework's own error listener, so that listener never answers an `/api` path.
 */
#[WithMonologChannel('application')]
final class TranslateApiException implements EventSubscriberInterface
{
    public function __construct(
        private readonly ErrorTranslator $translator,
        private readonly ReplyResponder $responder,
        private readonly LoggerInterface $application,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 10],
        ];
    }

    /**
     * Sets the error response for a path under `/api`. Any other path is left alone.
     */
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isApiPath($request->getPathInfo())) {
            return;
        }

        $error = $event->getThrowable();
        $reply = $this->translator->translate($error);
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

        $event->setResponse($this->responder->respond($reply, $headers));

        if ($reply->body->code === RequestFailure::Unauthenticated->value) {
            // The security listener would otherwise replace this body.
            $event->stopPropagation();
        }

        if ($reply->status >= 500) {
            $this->logFailure($request, $error, $reply);
        }
    }

    private function isApiPath(string $path): bool
    {
        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /**
     * @param Reply<ErrorResponse> $reply
     */
    private function logFailure(Request $request, \Throwable $error, Reply $reply): void
    {
        $context = [
            'exception' => $error,
            'code' => $reply->body->code,
        ];

        $enriched = $request->attributes->get(EnrichedRequest::class);

        if ($enriched instanceof EnrichedRequest) {
            $context['request_id'] = $enriched->requestId;
        }

        try {
            $this->application->error('http.request.failed', $context);
        } catch (\Throwable) {
            // A failed write must not change the error response.
        }
    }
}
