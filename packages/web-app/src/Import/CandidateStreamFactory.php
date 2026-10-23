<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Opens an import file as a candidate stream.
 */
interface CandidateStreamFactory
{
    /**
     * Opens `$path` and returns the stream of its candidates.
     *
     * @throws \RuntimeException When `$path` is not a readable file
     */
    public function open(string $path): CandidateStream;
}
