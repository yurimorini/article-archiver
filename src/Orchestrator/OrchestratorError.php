<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

/**
 * This enum names which part of an article fetch failed hard.
 *
 * Callers match these cases for exit codes or HTTP status. The stage exception on `OrchestratorException::getPrevious()` keeps the finer reason.
 */
enum OrchestratorError
{
    /** This case applies when the URL string was rejected before any request. */
    case UrlGuard;

    /** This case applies when the HTTP request failed, including a redirect that was not followed. */
    case Fetch;

    /** This case applies when the response bytes could not be turned into UTF-8 HTML. */
    case Encoding;

    /** This case applies when article extraction aborted. */
    case Extract;

    /** This case applies when HTML purification aborted. */
    case Sanitize;

    /** This case applies when a failure is not one of the stage errors above. */
    case Unexpected;

    /**
     * This method returns the standard message for this failure when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::UrlGuard => 'URL guard rejected the input URL',
            self::Fetch => 'HTTP fetch failed',
            self::Encoding => 'Character encoding normalization failed',
            self::Extract => 'Article extraction failed',
            self::Sanitize => 'HTML sanitization failed',
            self::Unexpected => 'Article pipeline failed unexpectedly',
        };
    }
}
