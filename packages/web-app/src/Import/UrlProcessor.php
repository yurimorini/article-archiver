<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Guards one candidate and, when the guard accepts it, archives it.
 *
 * A syntax, policy, or rejected URL is skipped and is not archived.
 */
#[Exclude]
final class UrlProcessor
{
    /**
     * Creates the processor from the URL guard and the owner's archive service.
     */
    public function __construct(
        /** The guard that rejects a candidate before any archive write. */
        private readonly UrlGuard $guard,
        /** The archive write for this run's owner. */
        private readonly ArchiveService $archives,
    ) {
    }

    /**
     * Returns the outcome of `$candidate`.
     *
     * A guard rejection does not call the archive write. Any throwable other than `ArchiveException` propagates.
     */
    public function process(string $candidate): ImportResult
    {
        try {
            $this->guard->guard($candidate);
        } catch (UrlGuardException $rejected) {
            return match ($rejected->error) {
                UrlGuardError::Syntax, UrlGuardError::Policy, UrlGuardError::Rejected => new ImportResult(ImportOutcome::Skipped, $candidate),
            };
        }

        try {
            $result = $this->archives->archive(new UrlPointer($candidate));
        } catch (ArchiveException $failure) {
            if ($this->isGuardCode($failure->failure)) {
                return new ImportResult(ImportOutcome::Skipped, $candidate, $failure->failure->value);
            }

            return new ImportResult(ImportOutcome::Failed, $candidate, $failure->failure->value);
        }

        return match ($result->outcome) {
            ArchiveOutcome::Written => new ImportResult(ImportOutcome::Archived, $candidate),
            ArchiveOutcome::AlreadyStored => new ImportResult(ImportOutcome::AlreadyStored, $candidate),
        };
    }

    /**
     * Returns whether `$failure` is a URL guard code reported by the archive write.
     */
    private function isGuardCode(ArchiveFailure $failure): bool
    {
        return $failure === ArchiveFailure::UrlSyntax
            || $failure === ArchiveFailure::UrlPolicy
            || $failure === ArchiveFailure::UrlRejected;
    }
}
