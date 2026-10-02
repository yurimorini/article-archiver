<?php

declare(strict_types=1);

namespace Yumo\LogRead\Orchestrator;

use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;

/**
 * This class is the command-line entry for fetching one article URL.
 *
 * It does not choose timeouts or parsers. It calls `Orchestrator::fetchArticle()` and maps the outcome onto a process exit code.
 *
 * `$exitCode = (new ArticleFetchCli($orchestrator))->run($argv, STDOUT, STDERR);`
 */
final class ArticleFetchCli
{
    /**
     * This constructor stores the fetcher this command calls.
     */
    public function __construct(
        /** This value performs the fetch. The command does not build stages itself. */
        private readonly Orchestrator $orchestrator,
    ) {
    }

    /**
     * This method reads the URL from `$argv[1]`, fetches it, and writes the article or an error message.
     *
     * A missing URL prints usage and returns 1. A fetched article is written to `$stdout` and returns 0.
     * An empty outcome writes a one-line notice to `$stderr` and also returns 0. A hard failure writes the error to `$stderr` and returns the code for that failure.
     *
     * @param list<string> $argv
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $url = $argv[1] ?? '';
        if ($url === '' || str_starts_with($url, '-')) {
            fwrite($stderr, "Usage: bin/run <url>\n");

            return 1;
        }

        try {
            $result = $this->orchestrator->fetchArticle($url);
        } catch (OrchestratorException $exception) {
            fwrite($stderr, $this->errorLine($exception) . "\n");

            return self::exitCode($exception);
        }

        if ($result instanceof PipelineNoContent) {
            fwrite($stderr, 'No article content (' . $result->reason->name . ")\n");

            return 0;
        }

        fwrite($stdout, $result->document->html);

        return 0;
    }

    /**
     * This method returns the process exit code for `$exception`.
     *
     * A redirect is 3. Any other download failure is 4. The remaining codes match the failure kind.
     */
    public static function exitCode(OrchestratorException $exception): int
    {
        return match ($exception->error) {
            OrchestratorError::UrlGuard => 2,
            OrchestratorError::Fetch => self::fetchExitCode($exception),
            OrchestratorError::Encoding => 5,
            OrchestratorError::Extract => 6,
            OrchestratorError::Sanitize => 7,
            OrchestratorError::Unexpected => 1,
        };
    }

    /**
     * This method returns 3 when the download failed because of a redirect, and 4 otherwise.
     */
    private static function fetchExitCode(OrchestratorException $exception): int
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof HttpFetcherException && $previous->error === HttpFetcherError::Redirect) {
            return 3;
        }

        return 4;
    }

    /**
     * This method returns the message written to stderr for `$exception`, including the stage detail when one exists.
     */
    private function errorLine(OrchestratorException $exception): string
    {
        $message = $exception->getMessage();
        $previous = $exception->getPrevious();
        if ($previous !== null && $previous->getMessage() !== '') {
            $message .= ': ' . $previous->getMessage();
        }

        return $message;
    }
}
