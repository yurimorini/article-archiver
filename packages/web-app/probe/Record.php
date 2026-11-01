<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Writes one probe run and its step files into a directory.
 */
final class Record
{
    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Stores the directory that will hold `run.json` and `steps/`.
     */
    public function __construct(
        private readonly string $runDirectory,
    ) {
    }

    /**
     * Writes `$run` to `run.json` as given.
     *
     * @param array<string, mixed> $run
     */
    public function writeRun(array $run): void
    {
        $this->writeJson($this->runDirectory . '/run.json', $run);
    }

    /**
     * Writes `$step` to `steps/%02d-%s.json` as given. Creates `steps/` on the first call.
     *
     * @param array<string, mixed> $step
     */
    public function writeStep(int $index, string $name, array $step): void
    {
        $stepsDirectory = $this->runDirectory . '/steps';
        if (!is_dir($stepsDirectory)) {
            mkdir($stepsDirectory);
        }

        $this->writeJson($this->runDirectory . '/' . sprintf('steps/%02d-%s.json', $index, $name), $step);
    }

    /**
     * Encodes `$payload` and writes it to `$path`.
     *
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, self::JSON_FLAGS));
    }
}
