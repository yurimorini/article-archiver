<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

use Psr\Log\LoggerInterface;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\FetchPolicy;

/**
 * This class holds the few settings a caller can change when building an article fetcher.
 *
 * Omitted settings use each stage’s own defaults. The URL safety check is not a setting here.
 *
 * `$orchestrator = OrchestratorFactory::create(new PipelineOptions(logger: $logger));`
 */
final readonly class PipelineOptions
{
    /**
     * This constructor stores the optional stage settings.
     */
    public function __construct(
        /** This value holds fetch timeouts, the body cap, and headers. Null uses `FetchPolicy` defaults. */
        public ?FetchPolicy $fetchPolicy = null,
        /** This value holds Readability limits and the debug switch. Null uses `ExtractPolicy` defaults. */
        public ?ExtractPolicy $extractPolicy = null,
        /** This value holds the URI scheme allowlist and the purifier debug switch. Null uses `PurifyPolicy` defaults. */
        public ?PurifyPolicy $purifyPolicy = null,
        /** This value receives hop logs. Null uses a logger that discards every record. */
        public ?LoggerInterface $logger = null,
        /**
         * This value is the directory where the HTML purifier writes its definition cache.
         * Null leaves the choice to the purifier, which uses a private per-user directory under the system temp directory.
         */
        public ?string $purifierCachePath = null,
    ) {
    }
}
