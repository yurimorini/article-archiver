<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Error;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\ArchiveFailure;

/**
 * Decides the HTTP status and the message a client sees for each archive failure.
 */
#[Exclude]
final class ArchiveFailurePresenter
{
    /**
     * Returns the HTTP status to answer with.
     */
    public function status(ArchiveFailure $failure): int
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
            ArchiveFailure::Unexpected => 500,
        };
    }

    /**
     * Returns the fixed message for people. `$upstreamStatus` only shapes the `upstream_status` message.
     */
    public function message(ArchiveFailure $failure, ?int $upstreamStatus): string
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
}
