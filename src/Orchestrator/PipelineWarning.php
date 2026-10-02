<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

/**
 * This class records one soft problem found while fetching an article.
 *
 * A warning does not mean the call failed. It can sit on a successful article or on an empty outcome.
 *
 * `$warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, $message);`
 */
final readonly class PipelineWarning
{
    /**
     * This constructor stores the warning code, a human-readable message, and an optional cause.
     */
    public function __construct(
        /** This value identifies which soft problem occurred. */
        public PipelineWarningCode $code,
        /** This value explains the problem in one sentence, usually taken from the stage that reported it. */
        public string $message,
        /** This value holds the stage exception that explains the warning, when one exists. */
        public ?\Throwable $previous = null,
    ) {
    }
}
