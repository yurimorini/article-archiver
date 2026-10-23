<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Reads one URL per line.
 *
 * The line ending is not part of the line. Leading and trailing ASCII spaces and tabs are not part of the candidate. A line that is empty after that trim is a blank candidate.
 *
 * `$stream = $factory->open('notes/urls.txt');`
 */
#[Exclude]
final class LineCandidateStream implements CandidateStream
{
    /** The open file. Closed when the candidates have been read. */
    private mixed $handle;

    /**
     * @param resource $handle an open file positioned at the start
     */
    public function __construct(mixed $handle)
    {
        if (!\is_resource($handle)) {
            throw new \InvalidArgumentException('The import file could not be read.');
        }
        $this->handle = $handle;
    }

    /**
     * Yields each candidate in file order.
     */
    public function candidates(): \Generator
    {
        foreach ($this->lines() as $line) {
            $value = trim($line, " \t");

            yield $value === '' ? null : $value;
        }
    }

    /**
     * Yields each raw line without its line ending.
     *
     * @return \Generator<int, string>
     */
    private function lines(): \Generator
    {
        try {
            $this->discardLeadingBom();
            while (($line = fgets($this->handle)) !== false) {
                yield $this->withoutLineEnding($line);
            }
        } finally {
            fclose($this->handle);
        }
    }

    /**
     * Drops a UTF-8 BOM at the start of the file. A BOM later in the file stays.
     */
    private function discardLeadingBom(): void
    {
        $prefix = fread($this->handle, 3);
        if ($prefix === "\xEF\xBB\xBF") {
            return;
        }
        if (\is_string($prefix) && $prefix !== '') {
            rewind($this->handle);
        }
    }

    /**
     * Removes one trailing LF and one trailing CR.
     *
     * Those bytes are the line ending. A CR that is not at the end of the line stays.
     */
    private function withoutLineEnding(string $line): string
    {
        if (str_ends_with($line, "\n")) {
            $line = substr($line, 0, -1);
        }
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return $line;
    }
}
