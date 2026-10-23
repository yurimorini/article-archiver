<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * Opens a one-field file as a line reader.
 */
final class LineCandidateStreamFactory implements CandidateStreamFactory
{
    /**
     * Opens `$path` and returns the line reader.
     *
     * @throws \RuntimeException When `$path` is not a readable file
     */
    public function open(string $path): CandidateStream
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('The import file could not be read.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The import file could not be read.');
        }

        return new LineCandidateStream($handle);
    }
}
