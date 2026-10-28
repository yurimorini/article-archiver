<?php

declare(strict_types=1);

namespace Yumo\ArtcleReader\Orchestrator;

use Psr\Log\NullLogger;
use Yumo\ArtcleReader\ArticleExtractor\ArticleExtractor;
use Yumo\ArtcleReader\ArticleExtractor\ExtractPolicy;
use Yumo\ArtcleReader\EncodingNormalizer\EncodingNormalizer;
use Yumo\ArtcleReader\HtmlSanitizer\HtmlSanitizer;
use Yumo\ArtcleReader\HtmlSanitizer\PurifyPolicy;
use Yumo\ArtcleReader\HttpFetcher\FetchPolicy;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcher;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;

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
     * This method wires the guarded fetch and the later stages and returns an orchestrator.
     *
     * The same logger is given to the guarded fetch and to the HTTP fetcher. It is given to article extraction only when that stage’s debug switch is on.
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
            new GuardedFetch(
                new UrlGuard(),
                new HttpFetcher($fetchPolicy, null, $logger),
                $logger,
                $fetchPolicy->maxRedirectHops,
            ),
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
