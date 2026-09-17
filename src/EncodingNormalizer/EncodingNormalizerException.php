<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Thrown when UTF-8 HTML cannot be produced from the fetched document bytes.
 *
 * Inspect `$error` to tell undeclared charset, unsupported encoding, and conversion failures apart.
 */
final class EncodingNormalizerException extends \RuntimeException
{
    public function __construct(
        /** The encoding failure kind that caused this exception to be thrown. */
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
