<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\Orchestrator\ArticleFetchCli;
use Yumo\LogRead\Tests\EncodingNormalizer\FakeUtf8Converter;

final class ArticleFetchCliTest extends TestCase
{
    private const URL = 'https://example.com/article';

    public function test_prints_article_html_and_exits_zero(): void
    {
        $cli = new ArticleFetchCli(PipelineHarness::make([
            PipelineHarness::htmlResponse(PipelineHarness::articleHtml()),
        ]));

        $exitCode = $cli->run(['bin/run', self::URL], $stdout = $this->stream(), $stderr = $this->stream());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $this->read($stdout));
        self::assertSame('', $this->read($stderr));
    }

    public function test_no_content_exits_zero_and_leaves_stdout_empty(): void
    {
        $cli = new ArticleFetchCli(PipelineHarness::make([
            PipelineHarness::htmlResponse(" \n"),
        ]));

        $exitCode = $cli->run(['bin/run', self::URL], $stdout = $this->stream(), $stderr = $this->stream());

        self::assertSame(0, $exitCode);
        self::assertSame('', $this->read($stdout));
        self::assertStringContainsString('ExtractNoContent', $this->read($stderr));
    }

    public function test_index_script_without_a_url_prints_usage_and_exits_one(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/src/index.php'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            usleep(20_000);
        }

        if ($exitCode === null) {
            proc_terminate($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame(1, $exitCode, 'src/index.php kept running without a URL');
        self::assertSame('', $stdout);
        self::assertSame("Usage: bin/run <url>\n", $stderr);
    }

    public function test_missing_url_prints_usage_and_exits_one(): void
    {
        $cli = new ArticleFetchCli(PipelineHarness::make());

        $exitCode = $cli->run(['bin/run'], $stdout = $this->stream(), $stderr = $this->stream());

        self::assertSame(1, $exitCode);
        self::assertSame('', $this->read($stdout));
        self::assertStringContainsString('Usage: bin/run <url>', $this->read($stderr));
    }

    public function test_url_guard_failure_exits_two(): void
    {
        $cli = new ArticleFetchCli(PipelineHarness::make());

        $exitCode = $cli->run(['bin/run', 'ftp://example.com/file'], $this->stream(), $stderr = $this->stream());

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('URL guard rejected the input URL', $this->read($stderr));
    }

    public function test_redirect_exits_three_and_other_fetch_failures_exit_four(): void
    {
        $redirect = new ArticleFetchCli(PipelineHarness::make([
            new Response(302, ['Location' => 'https://example.com/other'], ''),
        ]));
        $missing = new ArticleFetchCli(PipelineHarness::make([
            new Response(404, ['Content-Type' => 'text/html'], 'missing'),
        ]));

        self::assertSame(3, $redirect->run(['bin/run', self::URL], $this->stream(), $this->stream()));
        self::assertSame(4, $missing->run(['bin/run', self::URL], $this->stream(), $this->stream()));
    }

    public function test_encoding_extract_and_sanitize_failures_use_their_exit_codes(): void
    {
        $encoding = new ArticleFetchCli(PipelineHarness::make(
            [PipelineHarness::htmlResponse('<html>ok</html>', 'text/html; charset=ISO-8859-1')],
            encodingNormalizer: new EncodingNormalizer(new FakeUtf8Converter(
                new \RuntimeException('strict failed'),
                new \RuntimeException('lossy failed'),
            )),
        ));
        $extract = new ArticleFetchCli(PipelineHarness::make(
            [PipelineHarness::htmlResponse('<html><body>' . str_repeat('<span>x</span>', 40) . '</body></html>')],
            extractPolicy: new ExtractPolicy(maxElemsToParse: 10),
        ));
        $cacheFile = tempnam(sys_get_temp_dir(), 'lr-cache-');
        if ($cacheFile === false) {
            self::fail('Could not create a temp cache file');
        }

        try {
            $sanitize = new ArticleFetchCli(PipelineHarness::make(
                [PipelineHarness::htmlResponse(PipelineHarness::articleHtml())],
                htmlSanitizer: new HtmlSanitizer(new PurifyPolicy(), $cacheFile),
            ));

            self::assertSame(5, $encoding->run(['bin/run', self::URL], $this->stream(), $this->stream()));
            self::assertSame(6, $extract->run(['bin/run', self::URL], $this->stream(), $this->stream()));
            self::assertSame(7, $sanitize->run(['bin/run', self::URL], $this->stream(), $this->stream()));
        } finally {
            unlink($cacheFile);
        }
    }

    /**
     * This method opens a memory stream the CLI can write to.
     *
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Could not open a memory stream');
        }

        return $stream;
    }

    /**
     * This method returns everything written to `$stream`.
     *
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertNotFalse($contents);

        return $contents;
    }
}
