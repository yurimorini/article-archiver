<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Thrown when `EncodingNormalizer` cannot produce UTF-8 HTML at all.
 *
 * Inspect `$error` to tell undeclared, unsupported, and conversion failures apart.
 * Lossy-but-valid UTF-8 is not this exception: that is `EncodingOutcome::degraded()`.
 */
final class EncodingNormalizerException extends \RuntimeException
{
    public function __construct(
        /** Why UTF-8 HTML could not be produced. */
        public readonly EncodingError $error,
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
