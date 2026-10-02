<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

/**
 * This enum names why a fetch produced no article body to store.
 *
 * Both cases are successful calls in the sense that the URL was fetched. They mean “do not store an article body”.
 */
enum PipelineEmptyReason
{
    /** This case applies when article extraction found no article HTML. */
    case ExtractNoContent;

    /** This case applies when purification removed every element and left empty HTML. */
    case SanitizedEmpty;
}
