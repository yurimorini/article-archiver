<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveFailureMapper;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves which archive failure each library failure becomes.
 */
final class ArchiveFailureMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{OrchestratorException, ArchiveFailure, ?int}>
     */
    public static function orchestratorFailures(): iterable
    {
        yield 'url guard syntax' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Syntax)),
            ArchiveFailure::UrlSyntax,
            null,
        ];
        yield 'url guard policy' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Policy)),
            ArchiveFailure::UrlPolicy,
            null,
        ];
        yield 'url guard rejected' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new UrlGuardException(UrlGuardError::Rejected)),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'url guard without a previous' => [
            new OrchestratorException(OrchestratorError::UrlGuard),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'url guard with another previous' => [
            new OrchestratorException(OrchestratorError::UrlGuard, '', new \LogicException('x')),
            ArchiveFailure::UrlRejected,
            null,
        ];
        yield 'fetch transport' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::Transport)),
            ArchiveFailure::Transport,
            null,
        ];
        yield 'fetch http status' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::HttpStatus, statusCode: 503)),
            ArchiveFailure::UpstreamStatus,
            503,
        ];
        yield 'fetch http status without a number' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::HttpStatus)),
            ArchiveFailure::UpstreamStatus,
            null,
        ];
        yield 'fetch redirect' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::Redirect, statusCode: 301)),
            ArchiveFailure::Redirect,
            null,
        ];
        yield 'fetch content type' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::ContentType)),
            ArchiveFailure::ContentType,
            null,
        ];
        yield 'fetch body too large' => [
            self::fetchFailure(new HttpFetcherException(HttpFetcherError::BodyTooLarge)),
            ArchiveFailure::BodyTooLarge,
            null,
        ];
        yield 'fetch without a previous' => [
            new OrchestratorException(OrchestratorError::Fetch),
            ArchiveFailure::Unexpected,
            null,
        ];
        yield 'fetch with another previous' => [
            self::fetchFailure(new \RuntimeException('x')),
            ArchiveFailure::Unexpected,
            null,
        ];
        yield 'encoding' => [new OrchestratorException(OrchestratorError::Encoding), ArchiveFailure::Encoding, null];
        yield 'extract' => [new OrchestratorException(OrchestratorError::Extract), ArchiveFailure::Extract, null];
        yield 'sanitize' => [new OrchestratorException(OrchestratorError::Sanitize), ArchiveFailure::Sanitize, null];
        yield 'unexpected' => [
            new OrchestratorException(OrchestratorError::Unexpected),
            ArchiveFailure::Unexpected,
            null,
        ];
    }

    #[DataProvider('orchestratorFailures')]
    public function test_orchestrator_failures_map_by_stage_and_reason(
        OrchestratorException $exception,
        ArchiveFailure $expected,
        ?int $upstreamStatus,
    ): void {
        $archiveException = new ArchiveFailureMapper()->fromOrchestrator($exception);

        self::assertSame($expected, $archiveException->failure);
        self::assertSame($upstreamStatus, $archiveException->upstreamStatus);
        self::assertSame($exception, $archiveException->getPrevious());
    }

    public function test_empty_outcomes_map_by_reason(): void
    {
        $mapper = new ArchiveFailureMapper();

        $noArticle = $mapper->fromEmpty(PipelineFixtures::noArticle());
        $sanitizedEmpty = $mapper->fromEmpty(PipelineFixtures::noContent());

        self::assertSame(ArchiveFailure::ExtractNoContent, $noArticle->failure);
        self::assertNull($noArticle->getPrevious());
        self::assertSame(ArchiveFailure::SanitizedEmpty, $sanitizedEmpty->failure);
        self::assertNull($sanitizedEmpty->getPrevious());
    }

    private static function fetchFailure(\Throwable $previous): OrchestratorException
    {
        return new OrchestratorException(OrchestratorError::Fetch, '', $previous);
    }
}
