<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Successful guard outcome: pin-ready target plus raw input for logging.
 */
final class GuardResult
{
    public function __construct(
        public readonly SafeFetchTarget $safe,
        public readonly string $original,
    ) {
    }
}
