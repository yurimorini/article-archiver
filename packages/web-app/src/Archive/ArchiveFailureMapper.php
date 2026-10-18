<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineEmptyReason;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;

/**
 * Turns the failures of the article library into an `ArchiveException`.
 *
 * `$exception = (new ArchiveFailureMapper())->fromOrchestrator($failure);`
 */
#[Exclude]
final class ArchiveFailureMapper
{
    /**
     * Maps a failed fetch to the archive failure its stage and finer reason name.
     *
     * The failed fetch is kept as the cause.
     */
    public function fromOrchestrator(OrchestratorException $exception): ArchiveException
    {
        $failure = $this->failureOf($exception);
        $previous = $exception->getPrevious();
        $upstreamStatus = $failure === ArchiveFailure::UpstreamStatus && $previous instanceof HttpFetcherException
            ? $previous->statusCode
            : null;

        return new ArchiveException($failure, $exception, $upstreamStatus);
    }

    /**
     * Maps a fetch that finished without an article to the archive failure of its reason.
     */
    public function fromEmpty(PipelineNoContent $outcome): ArchiveException
    {
        return new ArchiveException(match ($outcome->reason) {
            PipelineEmptyReason::ExtractNoContent => ArchiveFailure::ExtractNoContent,
            PipelineEmptyReason::SanitizedEmpty => ArchiveFailure::SanitizedEmpty,
        });
    }

    private function failureOf(OrchestratorException $exception): ArchiveFailure
    {
        $previous = $exception->getPrevious();

        return match ($exception->error) {
            OrchestratorError::UrlGuard => $this->urlGuardFailure($previous),
            OrchestratorError::Fetch => $this->fetchFailure($previous),
            OrchestratorError::Encoding => ArchiveFailure::Encoding,
            OrchestratorError::Extract => ArchiveFailure::Extract,
            OrchestratorError::Sanitize => ArchiveFailure::Sanitize,
            OrchestratorError::Unexpected => ArchiveFailure::Unexpected,
        };
    }

    private function urlGuardFailure(?\Throwable $previous): ArchiveFailure
    {
        if (!$previous instanceof UrlGuardException) {
            return ArchiveFailure::UrlRejected;
        }

        return match ($previous->error) {
            UrlGuardError::Syntax => ArchiveFailure::UrlSyntax,
            UrlGuardError::Policy => ArchiveFailure::UrlPolicy,
            UrlGuardError::Rejected => ArchiveFailure::UrlRejected,
        };
    }

    private function fetchFailure(?\Throwable $previous): ArchiveFailure
    {
        if (!$previous instanceof HttpFetcherException) {
            return ArchiveFailure::Unexpected;
        }

        return match ($previous->error) {
            HttpFetcherError::Transport => ArchiveFailure::Transport,
            HttpFetcherError::HttpStatus => ArchiveFailure::UpstreamStatus,
            HttpFetcherError::Redirect => ArchiveFailure::Redirect,
            HttpFetcherError::ContentType => ArchiveFailure::ContentType,
            HttpFetcherError::BodyTooLarge => ArchiveFailure::BodyTooLarge,
        };
    }
}
