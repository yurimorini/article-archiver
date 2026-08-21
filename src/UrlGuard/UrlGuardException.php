<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

final class UrlGuardException extends \RuntimeException
{
    public function __construct(
        public readonly UrlGuardError $error,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $error->defaultMessage(),
            0,
            $previous,
        );
    }
}
