<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Request;

/**
 * Decides whether a client-supplied request id is kept.
 */
interface RequestIdFactory
{
    /**
     * Keeps a value of 1 to 128 ASCII letters, digits, `.`, `_`, or `-`.
     * Any other value, including null, is replaced.
     */
    public function fromHeader(?string $value): string;
}
