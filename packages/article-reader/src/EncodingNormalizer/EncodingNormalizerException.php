<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This exception reports that UTF-8 HTML could not be produced from the response bytes.
 *
 * You can inspect `$error` to distinguish undeclared charset, unsupported encoding, and conversion failures.
 */
final class EncodingNormalizerException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified encoding error.
     */
    public function __construct(
        /** This value identifies why UTF-8 HTML could not be produced. */
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
