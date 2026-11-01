<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * One HTTP request the probe sends.
 */
final readonly class Preset
{
    /**
     * Creates a request row from its method, path, content type, body, and authorization.
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $contentType,
        public ?string $body,
        public string $authorization,
    ) {
    }
}
