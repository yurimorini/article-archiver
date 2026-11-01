<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Starts probe jobs in band order and writes each step as it settles.
 */
final class Scheduler
{
    /** @var array<string, Job> */
    private array $jobs = [];

    /** @var array<string, true> */
    private array $started = [];

    /** @var array<string, true> */
    private array $inFlight = [];

    /** @var array<string, true> */
    private array $done = [];

    private bool $failed = false;

    /** @var \Closure(): list<string> */
    private readonly \Closure $logLines;

    /** @var resource */
    private $stderr;

    /**
     * Stores the job seam, the run directory, the HTTP origin, the article URL, and the dev-log reader.
     *
     * `$logLines` returns raw log lines. This scheduler passes them to `LogTail` when a response has a request id.
     * An omitted reader is the empty list. A closure is not a legal parameter default, so `null` stands in for that reader.
     * A server failure or a step error is one sentence on `$stderr`.
     *
     * @param (\Closure(): list<string>)|null $logLines
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly Io $io,
        private readonly Record $record,
        private readonly string $baseUrl,
        private readonly string $archiveUrl,
        ?\Closure $logLines = null,
        $stderr = null,
    ) {
        $this->logLines = $logLines ?? static fn (): array => [];
        if (!is_resource($stderr)) {
            $stderr = STDERR;
        }
        $this->stderr = $stderr;
    }

    /**
     * Runs `$selected` and returns 0 when every step settled without an error.
     *
     * @param list<string> $selected
     */
    public function run(array $selected): int
    {
        $this->reset();

        try {
            $this->io->start(new Job('server', 'server', null, null));
            $server = $this->awaitSettled('server');
            if ($server->error !== null) {
                $this->report($server->job->name, $server->error);

                return 1;
            }

            $this->startReady($selected);
            while (!$this->finished($selected)) {
                $batch = $this->io->poll();
                if ($batch === []) {
                    if ($this->inFlight !== []) {
                        usleep(10000);
                        continue;
                    }

                    throw new \LogicException('Selected steps are still pending and nothing is in flight.');
                }

                foreach ($batch as $settled) {
                    $this->accept($selected, $settled);
                }

                $this->startReady($selected);
            }

            return $this->failed ? 1 : 0;
        } finally {
            $this->io->stop();
        }
    }

    private function reset(): void
    {
        $this->jobs = [];
        $this->started = [];
        $this->inFlight = [];
        $this->done = [];
        $this->failed = false;
    }

    private function awaitSettled(string $name): Settled
    {
        while (true) {
            foreach ($this->io->poll() as $settled) {
                if ($settled->job->name === $name) {
                    return $settled;
                }
            }

            usleep(10000);
        }
    }

    /**
     * @param list<string> $selected
     */
    private function startReady(array $selected): void
    {
        foreach ($selected as $name) {
            if (isset($this->started[$name]) || !$this->canStart($name, $selected)) {
                continue;
            }

            $this->startNamed($name);
        }
    }

    /**
     * @param list<string> $selected
     */
    private function canStart(string $name, array $selected): bool
    {
        if (in_array($name, Catalog::refusals(), true)) {
            return true;
        }

        if (in_array($name, Catalog::writeChain(), true)) {
            return $this->predecessorsSettled($name, $selected);
        }

        if (in_array($name, Catalog::reads(), true)) {
            return $this->selectedWritesSettled($selected);
        }

        return false;
    }

    /**
     * @param list<string> $selected
     */
    private function predecessorsSettled(string $name, array $selected): bool
    {
        foreach (Catalog::writeChain() as $write) {
            if ($write === $name) {
                return true;
            }

            if (in_array($write, $selected, true) && !isset($this->done[$write])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $selected
     */
    private function selectedWritesSettled(array $selected): bool
    {
        foreach (Catalog::writeChain() as $write) {
            if (in_array($write, $selected, true) && !isset($this->done[$write])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $selected
     */
    private function finished(array $selected): bool
    {
        foreach ($selected as $name) {
            if (!isset($this->done[$name])) {
                return false;
            }
        }

        return true;
    }

    private function startNamed(string $name): void
    {
        $job = $this->jobFor($name);
        $this->jobs[$name] = $job;
        $this->started[$name] = true;
        $this->inFlight[$name] = true;
        $this->io->start($job);
    }

    private function jobFor(string $name): Job
    {
        if ($name === 'import') {
            return new Job('import', $name, null, null);
        }

        if ($name === 'sql') {
            return new Job('sql', $name, null, $this->archiveUrl);
        }

        $url = Catalog::usesDrawnUrl($name) ? $this->archiveUrl : null;

        return new Job('http', $name, Catalog::preset($name), $url);
    }

    /**
     * @param list<string> $selected
     */
    private function accept(array $selected, Settled $settled): void
    {
        $name = $settled->job->name;
        unset($this->inFlight[$name]);

        if (!in_array($name, $selected, true)) {
            return;
        }

        $this->done[$name] = true;
        if ($settled->error !== null) {
            $this->failed = true;
            $this->report($name, $settled->error);
        }

        $this->writeStep($selected, $settled);
    }

    /**
     * Writes one failure sentence for `$name`.
     */
    private function report(string $name, string $error): void
    {
        fwrite($this->stderr, $name . ': ' . $error . "\n");
    }

    /**
     * @param list<string> $selected
     */
    private function writeStep(array $selected, Settled $settled): void
    {
        $name = $settled->job->name;
        $position = array_search($name, $selected, true);
        if (!is_int($position)) {
            throw new \LogicException('Settled step was not selected.');
        }

        $job = $this->jobs[$name];
        $step = [
            'name' => $name,
            'request' => null,
            'response' => null,
            'log' => null,
            'import' => null,
            'sql' => null,
            'error' => $settled->error,
        ];

        if ($job->kind === 'http') {
            $step['request'] = $this->httpRequest($name, $job, $settled->path);
            $step['log'] = $this->httpLog($settled);
            if ($settled->error === null) {
                $step['response'] = [
                    'status' => $settled->status,
                    'headers' => $settled->headers,
                    'body' => $settled->body,
                ];
            }
        } elseif ($job->kind === 'import') {
            $step['import'] = [
                'exit' => $settled->exitCode,
                'stdout' => $settled->stdout,
                'stderr' => $settled->stderr,
                'errorLog' => $settled->errorLog,
            ];
        } elseif ($job->kind === 'sql') {
            $step['sql'] = [
                'file' => $settled->sqlFile,
                'rows' => $settled->rows,
            ];
        }

        $this->record->writeStep($position + 1, $name, $step);
    }

    /**
     * Returns the dev-log objects for the response request id.
     *
     * A response without `x-request-id` keeps an empty log.
     *
     * @return list<array<string, mixed>>
     */
    private function httpLog(Settled $settled): array
    {
        $requestId = $settled->headers['x-request-id'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            return [];
        }

        $lines = ($this->logLines)();
        if (!is_array($lines)) {
            return [];
        }

        $raw = [];
        foreach ($lines as $line) {
            if (is_string($line)) {
                $raw[] = $line;
            }
        }

        return LogTail::forRequest($raw, $requestId);
    }

    /**
     * @return array{method: string, url: string, authorization: string, contentType: ?string, body: ?string}
     */
    private function httpRequest(string $name, Job $job, ?string $path = null): array
    {
        $preset = $job->preset;
        if (!$preset instanceof Preset) {
            throw new \LogicException('HTTP job has no preset.');
        }

        $body = $preset->body;
        if ($name === 'archive' || $name === 'archive-again') {
            $body = json_encode(['url' => $this->archiveUrl], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return [
            'method' => $preset->method,
            'url' => $this->baseUrl . ($path ?? $preset->path),
            'authorization' => $preset->authorization,
            'contentType' => $preset->contentType,
            'body' => $body,
        ];
    }
}
