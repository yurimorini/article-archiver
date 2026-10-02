<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This exception reports that HTML purification aborted for one article.
 *
 * Inspect `$error` to tell a configuration or cache failure from an unexpected
 * failure. Dirty markup does not throw: tags and URLs are stripped and a
 * `SafeDocument` is returned.
 */
final class HtmlSanitizerException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified purification failure.
     */
    public function __construct(
        /** This value identifies why purification aborted. */
        public readonly HtmlSanitizerError $error,
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
