<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

enum UrlGuardError
{
    case Syntax;
    case Policy;
    case Rejected;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Syntax => 'URL is missing, malformed, or has no host',
            self::Policy => 'URL scheme or credentials are not allowed',
            self::Rejected => 'URL was rejected by SSRF/DNS validation',
        };
    }
}
