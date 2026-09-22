<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use Psr\Log\LoggerInterface;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

/**
 * This class extracts article HTML and a few metadata fields from a UTF-8 page.
 *
 * It wraps Readability and maps the vendor article onto `ReadableDocument`.
 * Blank HTML returns `ExtractResult::noContent()` without calling Readability.
 * The article HTML is not checked for scripts or unsafe URLs.
 *
 * `$result = (new ArticleExtractor())->extract($utf8Html);`
 */
final class ArticleExtractor
{
    /** This value holds the Readability knobs for every `extract()` call. */
    private ExtractPolicy $policy;

    /** This value holds an optional PSR-3 logger. It is passed to Readability only when `$policy->debug` is true. */
    private ?LoggerInterface $logger;

    /**
     * This constructor stores the policy and logger. Omitted arguments use a default `ExtractPolicy` and no logger.
     */
    public function __construct(
        ?ExtractPolicy $policy = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->policy = $policy ?? new ExtractPolicy();
        $this->logger = $logger;
    }

    /**
     * This method extracts an article from `$html`, or returns NoContent when the document is blank.
     *
     * @throws ArticleExtractorException When extraction aborts
     */
    public function extract(Utf8Html $html): ExtractResult
    {
        if (trim($html->html) === '') {
            return ExtractResult::noContent($html->sourceUrl);
        }

        $article = new Readability($this->configurationFor($html))->parse($html->html);
        $title = PlainText::fromUntrusted($article->title);
        $excerpt = PlainText::fromUntrustedNullable($article->excerpt);
        $siteName = PlainText::fromUntrustedNullable($article->siteName);

        if (!$article->hasContent()) {
            return ExtractResult::noContent($html->sourceUrl, $title, $excerpt, $siteName);
        }

        $content = $article->content;
        if (!is_string($content)) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected);
        }

        return ExtractResult::ok(new ReadableDocument(
            title: $title,
            excerpt: $excerpt,
            siteName: $siteName,
            content: $content,
            sourceUrl: $html->sourceUrl,
        ));
    }

    /**
     * This method builds the vendor configuration for one page.
     *
     * Relative URLs are rewritten against `$html->sourceUrl`. The element cap
     * is the project default of 30000 until a later change reads it from the policy.
     * The logger is included only when debug is on. Readability writes to a PSR-3
     * logger even when its own debug flag is false.
     */
    private function configurationFor(Utf8Html $html): Configuration
    {
        return new Configuration(
            debug: $this->policy->debug,
            logger: $this->policy->debug ? $this->logger : null,
            charThreshold: $this->policy->charThreshold,
            fixRelativeURLs: true,
            originalURL: $html->sourceUrl,
            maxElemsToParse: 30000,
        );
    }
}
