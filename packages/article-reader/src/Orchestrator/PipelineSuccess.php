<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Orchestrator;

use Yumo\ArtcleReader\HtmlSanitizer\SafeDocument;

/**
 * This class is a finished fetch whose article HTML is safe to store or display.
 *
 * `$document->html` is never empty or whitespace. When `isDegraded()` is true, read `$warnings` before treating the article as a clean copy.
 *
 * `$result = $orchestrator->fetchArticle($url);`
 */
final readonly class PipelineSuccess
{
    /**
     * This constructor stores the purified article, fetch metadata, and any soft warnings.
     *
     * @param list<string>          $hopHistory
     * @param list<PipelineWarning> $warnings
     *
     * @throws \InvalidArgumentException When `$document->html` is empty or whitespace
     */
    public function __construct(
        /** This value holds the purified article. Its HTML is not empty. */
        public SafeDocument $document,
        /** This value holds the URL string passed into the fetch, stored unchanged. */
        public string $originalUrl,
        /** This value holds redirect hop URLs recorded during fetch, in order. */
        public array $hopHistory,
        /** This value holds soft problems that did not remove the article, such as a lossy encoding conversion. */
        public array $warnings = [],
    ) {
        if (trim($document->html) === '') {
            throw new \InvalidArgumentException('Successful article HTML must not be empty');
        }
    }

    /**
     * This method returns whether the article was produced with at least one soft warning.
     */
    public function isDegraded(): bool
    {
        return $this->warnings !== [];
    }
}
