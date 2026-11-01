<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Names one server, import, HTTP, or SQL job.
 */
final readonly class Job
{
    /**
     * Stores the kind, name, preset, and URL for one job.
     */
    public function __construct(
        public string $kind,
        public string $name,
        public ?Preset $preset,
        public ?string $url,
    ) {
    }
}
