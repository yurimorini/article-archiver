<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

/**
 * This exception reports that fetching one URL aborted.
 *
 * Inspect `$error` for the stage that failed. `getPrevious()` is the stage exception when the failure came from a stage.
 * An empty article and a lossy encoding conversion do not throw: those are `PipelineNoContent` and `PipelineWarning`.
 */
final class OrchestratorException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified pipeline failure.
     */
    public function __construct(
        /** This value identifies which part of the fetch failed. */
        public readonly OrchestratorError $error,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $error->defaultMessage(),
            0,
            $previous,
        );
    }
}
