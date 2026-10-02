<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This exception reports that article extraction aborted for one document.
 *
 * Inspect `$error` to tell an oversized document from an unexpected failure.
 * A page with no article body does not throw: that is `ExtractResult::noContent()`.
 */
final class ArticleExtractorException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified extraction failure.
     */
    public function __construct(
        /** This value identifies why extraction aborted. */
        public readonly ArticleExtractorError $error,
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
