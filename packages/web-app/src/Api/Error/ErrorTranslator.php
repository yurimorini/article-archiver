<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Error;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Yumo\Eleanor\Api\Response\ErrorResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;

/**
 * Turns any throwable into the error reply a client sees.
 *
 * The reply holds fixed texts only. The text of the throwable never reaches it.
 */
final class ErrorTranslator
{
    /**
     * Creates the translator.
     */
    public function __construct(private readonly ArchiveFailurePresenter $archive = new ArchiveFailurePresenter())
    {
    }

    /**
     * Returns the error reply for the throwable.
     *
     * @return Reply<ErrorResponse>
     */
    public function translate(\Throwable $error): Reply
    {
        if ($error instanceof ArchiveException) {
            return $this->archiveReply($error);
        }

        $request = $this->requestFailure($error);
        if ($request !== null) {
            return new Reply($request->status(), new ErrorResponse($request->value, $request->message()));
        }

        return $this->archiveReply(new ArchiveException(ArchiveFailure::Unexpected, $error));
    }

    /**
     * @return Reply<ErrorResponse>
     */
    private function archiveReply(ArchiveException $error): Reply
    {
        $failure = $error->failure;

        return new Reply(
            $this->archive->status($failure),
            new ErrorResponse($failure->value, $this->archive->message($failure, $error->upstreamStatus)),
        );
    }

    private function requestFailure(\Throwable $error): ?RequestFailure
    {
        return match (true) {
            $error instanceof NotFoundHttpException => RequestFailure::NotFound,
            $error instanceof MethodNotAllowedHttpException => RequestFailure::MethodNotAllowed,
            $error instanceof UnsupportedMediaTypeHttpException => RequestFailure::UnsupportedMediaType,
            $error instanceof HttpExceptionInterface && $error->getStatusCode() === 400 => $this->badRequest($error),
            default => null,
        };
    }

    private function badRequest(\Throwable $error): RequestFailure
    {
        if ($error->getPrevious() instanceof NotEncodableValueException) {
            return RequestFailure::InvalidJson;
        }

        return RequestFailure::InvalidBody;
    }
}
