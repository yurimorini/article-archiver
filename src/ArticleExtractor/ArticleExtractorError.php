<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This enum names hard failures that abort article extraction for one document.
 *
 * A page with no article body is not one of these cases. That outcome is
 * `ExtractResult::noContent()`.
 */
enum ArticleExtractorError
{
    /** This case applies when the document has more elements than `ExtractPolicy::$maxElemsToParse` allows. */
    case TooLarge;

    /** This case applies when the adapter catches a throwable that is not an empty-input or element-limit parse failure. */
    case Unexpected;

    /**
     * This method returns the standard message for this failure when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::TooLarge => 'HTML document exceeds the configured element limit',
            self::Unexpected => 'Article extraction failed unexpectedly',
        };
    }
}
