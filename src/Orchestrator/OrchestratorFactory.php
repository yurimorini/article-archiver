<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

use Psr\Log\NullLogger;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\UrlGuard\UrlGuard;

/**
 * This class builds an `Orchestrator` with the production defaults.
 *
 * The URL safety check always uses the production DNS and SSRF checker. Tests that need a fixed IP list construct `Orchestrator` directly.
 *
 * `$orchestrator = OrchestratorFactory::create();`
 */
final class OrchestratorFactory
{
    /**
     * This method wires the five stages and returns an orchestrator.
     *
     * The same logger is always given to the HTTP fetcher. It is given to article extraction only when that stage’s debug switch is on.
     * When `$options` is omitted, every stage uses its own defaults and logs are discarded.
     */
    public static function create(?PipelineOptions $options = null): Orchestrator
    {
        $options ??= new PipelineOptions();
        $logger = $options->logger ?? new NullLogger();
        $fetchPolicy = $options->fetchPolicy ?? new FetchPolicy();
        $extractPolicy = $options->extractPolicy ?? new ExtractPolicy();
        $purifyPolicy = $options->purifyPolicy ?? new PurifyPolicy();

        return new Orchestrator(
            new UrlGuard(),
            new HttpFetcher($fetchPolicy, null, $logger),
            new EncodingNormalizer(),
            new ArticleExtractor(
                $extractPolicy,
                $extractPolicy->debug ? $logger : null,
            ),
            new HtmlSanitizer($purifyPolicy, $options->purifierCachePath),
            $logger,
        );
    }
}
