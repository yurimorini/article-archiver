<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

/**
 * This enum names a soft problem that did not stop the article pipeline.
 *
 * The list can grow. The only case today is a lossy character-encoding conversion.
 */
enum PipelineWarningCode
{
    /** This case applies when UTF-8 HTML was produced, but the conversion was lossy or only guessed. */
    case EncodingDegraded;
}
