<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Error;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
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
    private readonly AuthenticationTrustResolverInterface $trustResolver;

    /**
     * Creates the translator. Without a token storage no call counts as fully authenticated.
     */
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
        ?AuthenticationTrustResolverInterface $trustResolver = null,
    ) {
        $this->trustResolver = $trustResolver ?? new AuthenticationTrustResolver();
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
            $this->status($failure),
            new ErrorResponse($failure->value, $this->message($failure, $error->upstreamStatus)),
        );
    }

    /**
     * Returns the HTTP status to answer with.
     */
    private function status(ArchiveFailure $failure): int
    {
        return match ($failure) {
            ArchiveFailure::UrlSyntax,
            ArchiveFailure::UrlPolicy,
            ArchiveFailure::UrlRejected => 400,
            ArchiveFailure::ExtractNoContent,
            ArchiveFailure::SanitizedEmpty,
            ArchiveFailure::Redirect,
            ArchiveFailure::ContentType,
            ArchiveFailure::BodyTooLarge => 422,
            ArchiveFailure::Transport,
            ArchiveFailure::UpstreamStatus => 502,
            ArchiveFailure::Encoding,
            ArchiveFailure::Extract,
            ArchiveFailure::Sanitize,
            ArchiveFailure::NotWritten,
            ArchiveFailure::Unexpected => 500,
        };
    }

    /**
     * Returns the fixed message for people. `$upstreamStatus` only shapes the `upstream_status` message.
     */
    private function message(ArchiveFailure $failure, ?int $upstreamStatus): string
    {
        return match ($failure) {
            ArchiveFailure::UrlSyntax => 'The URL is not valid.',
            ArchiveFailure::UrlPolicy => 'The URL is not allowed.',
            ArchiveFailure::UrlRejected => 'The URL was refused.',
            ArchiveFailure::ExtractNoContent => 'The page has no article.',
            ArchiveFailure::SanitizedEmpty => 'The article is empty after purification.',
            ArchiveFailure::Redirect => 'The page redirects. Send the final URL.',
            ArchiveFailure::ContentType => 'The page is not HTML.',
            ArchiveFailure::BodyTooLarge => 'The page is larger than the size limit.',
            ArchiveFailure::Transport => "The page's site could not be reached.",
            ArchiveFailure::UpstreamStatus => $this->upstreamMessage($upstreamStatus),
            ArchiveFailure::Encoding => "The page's character encoding could not be read.",
            ArchiveFailure::Extract => 'The article could not be extracted.',
            ArchiveFailure::Sanitize => 'The article could not be purified.',
            ArchiveFailure::NotWritten => 'The archive could not be stored.',
            ArchiveFailure::Unexpected => 'Something went wrong on the server.',
        };
    }

    private function upstreamMessage(?int $upstreamStatus): string
    {
        if ($upstreamStatus === null) {
            return "The page's site answered with an error status.";
        }

        return "The page's site answered with status {$upstreamStatus}.";
    }

    private function requestFailure(\Throwable $error): ?RequestFailure
    {
        return match (true) {
            $error instanceof AuthenticationException => RequestFailure::Unauthenticated,
            $error instanceof AccessDeniedException && !$this->isFullyAuthenticated() => RequestFailure::Unauthenticated,
            $error instanceof NotFoundHttpException => RequestFailure::NotFound,
            $error instanceof MethodNotAllowedHttpException => RequestFailure::MethodNotAllowed,
            $error instanceof UnsupportedMediaTypeHttpException => RequestFailure::UnsupportedMediaType,
            $error instanceof HttpExceptionInterface && $error->getStatusCode() === 400 => $this->badRequest($error),
            default => null,
        };
    }

    private function isFullyAuthenticated(): bool
    {
        return $this->tokenStorage !== null && $this->trustResolver->isFullFledged($this->tokenStorage->getToken());
    }

    private function badRequest(\Throwable $error): RequestFailure
    {
        if ($error->getPrevious() instanceof NotEncodableValueException) {
            return RequestFailure::InvalidJson;
        }

        return RequestFailure::InvalidBody;
    }
}
