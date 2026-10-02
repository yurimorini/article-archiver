<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\Orchestrator\OrchestratorFactory;
use Yumo\LogRead\Orchestrator\PipelineOptions;
use Yumo\LogRead\Tests\ArticleExtractor\RecordingLogger;

final class OrchestratorFactoryTest extends TestCase
{
    public function test_create_without_options_uses_null_logger_and_default_cache_path(): void
    {
        $orchestrator = OrchestratorFactory::create();

        $logger = $this->property($orchestrator, 'logger');
        self::assertInstanceOf(NullLogger::class, $logger);
        $fetcher = $this->property($orchestrator, 'httpFetcher');
        self::assertInstanceOf(HttpFetcher::class, $fetcher);
        self::assertSame($logger, $this->property($fetcher, 'logger'));
        $extractor = $this->property($orchestrator, 'articleExtractor');
        self::assertInstanceOf(ArticleExtractor::class, $extractor);
        self::assertNull($this->property($extractor, 'logger'));
        $sanitizer = $this->property($orchestrator, 'htmlSanitizer');
        self::assertInstanceOf(HtmlSanitizer::class, $sanitizer);
        $defaultSanitizer = new HtmlSanitizer();
        self::assertSame(
            $this->property($defaultSanitizer, 'definitionCachePath'),
            $this->property($sanitizer, 'definitionCachePath'),
        );
    }

    public function test_create_shares_one_logger_and_passes_it_to_the_extractor_only_when_debug_is_on(): void
    {
        $logger = new RecordingLogger();
        $fetchPolicy = new FetchPolicy(timeoutSeconds: 12);
        $extractPolicy = new ExtractPolicy(debug: true, charThreshold: 20);
        $purifyPolicy = new PurifyPolicy(debug: true);
        $cachePath = sys_get_temp_dir() . '/log-read-htmlpurifier-custom';

        $orchestrator = OrchestratorFactory::create(new PipelineOptions(
            fetchPolicy: $fetchPolicy,
            extractPolicy: $extractPolicy,
            purifyPolicy: $purifyPolicy,
            logger: $logger,
            purifierCachePath: $cachePath,
        ));

        self::assertSame($logger, $this->property($orchestrator, 'logger'));
        $fetcher = $this->property($orchestrator, 'httpFetcher');
        self::assertInstanceOf(HttpFetcher::class, $fetcher);
        self::assertSame($logger, $this->property($fetcher, 'logger'));
        self::assertSame($fetchPolicy, $this->property($fetcher, 'policy'));
        $extractor = $this->property($orchestrator, 'articleExtractor');
        self::assertInstanceOf(ArticleExtractor::class, $extractor);
        self::assertSame($logger, $this->property($extractor, 'logger'));
        self::assertSame($extractPolicy, $this->property($extractor, 'policy'));
        $sanitizer = $this->property($orchestrator, 'htmlSanitizer');
        self::assertInstanceOf(HtmlSanitizer::class, $sanitizer);
        self::assertSame($purifyPolicy, $this->property($sanitizer, 'policy'));
        self::assertSame($cachePath, $this->property($sanitizer, 'definitionCachePath'));
    }

    public function test_extract_debug_off_does_not_pass_the_logger_into_the_extractor(): void
    {
        $logger = new RecordingLogger();
        $orchestrator = OrchestratorFactory::create(new PipelineOptions(
            extractPolicy: new ExtractPolicy(debug: false),
            logger: $logger,
        ));

        $fetcher = $this->property($orchestrator, 'httpFetcher');
        self::assertInstanceOf(HttpFetcher::class, $fetcher);
        self::assertSame($logger, $this->property($fetcher, 'logger'));
        $extractor = $this->property($orchestrator, 'articleExtractor');
        self::assertInstanceOf(ArticleExtractor::class, $extractor);
        self::assertNull($this->property($extractor, 'logger'));
    }

    /**
     * This method reads a private collaborator so a test can check factory wiring without performing a request.
     */
    private function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}
