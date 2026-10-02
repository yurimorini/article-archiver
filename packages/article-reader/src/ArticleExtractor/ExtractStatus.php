<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This enum distinguishes an extracted article from a page that had no article body.
 */
enum ExtractStatus
{
    /** This case applies when `ExtractResult::$document` holds the article. */
    case Ok;

    /** This case applies when no article body was found. Metadata may still be present. */
    case NoContent;
}
