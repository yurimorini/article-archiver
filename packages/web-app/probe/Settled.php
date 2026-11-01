<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * The outcome of one job that has finished.
 */
final readonly class Settled
{
    /**
     * Stores the job and the fields a step file can record.
     *
     * @param array<string, string> $headers
     * @param list<array<string, mixed>>|null $rows
     * @param string|null $path The path that was requested, when it differs from the preset
     */
    public function __construct(
        public Job $job,
        public ?int $status,
        public ?string $body,
        public array $headers,
        public ?string $error,
        public ?int $exitCode,
        public ?string $stdout,
        public ?string $stderr,
        public ?string $errorLog,
        public ?array $rows,
        public ?string $sqlFile,
        public ?string $path = null,
    ) {
    }
}
