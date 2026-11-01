<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

use Symfony\Component\Dotenv\Dotenv;
use Yumo\Eleanor\Kernel;

/**
 * Runs one probe from the committed document.
 */
final class ProbeRun
{
    /**
     * Reads the probe document, records the run, and returns the scheduler status.
     *
     * A broken token, owner, selection, or config returns 1 before the scheduler starts.
     * A required draw that is missing or empty returns 1 before `probe/runs/` is created.
     * Each of those failures writes one sentence to `$stderr`.
     *
     * @param list<string> $argv
     * @param resource|null $stderr
     */
    public static function main(array $argv, $stderr = null): int
    {
        if (!is_resource($stderr)) {
            $stderr = STDERR;
        }

        try {
            $config = Config::fromFile(__DIR__ . '/probe.json');
            $selected = Selection::names($config->steps, $argv);
            $token = self::token();
            Guard::token($token);
            $owner = Guard::owner(self::parameterEmail(), $config->owner);
            $seed = $config->seed ?? random_int(1, PHP_INT_MAX);
            $draw = self::draw($config, $selected, $seed);
        } catch (\InvalidArgumentException $error) {
            return self::fail($stderr, $error->getMessage());
        }

        if ($draw === null) {
            return self::fail($stderr, 'The URL file is missing or empty.');
        }

        $stamp = time();
        $runDir = self::packageDir() . '/probe/runs/' . gmdate('Ymd\THis\Z', $stamp) . '-' . $seed;
        if (!is_dir($runDir) && !mkdir($runDir, 0o775, true)) {
            return self::fail($stderr, 'The run directory could not be created.');
        }

        if ($draw !== []) {
            $written = file_put_contents($runDir . '/sample.txt', implode("\n", $draw) . "\n");
            if ($written === false) {
                return self::fail($stderr, 'The sample file could not be written.');
            }
        }

        $baseUrl = 'http://127.0.0.1:' . $config->port;
        $record = new Record($runDir);
        $record->writeRun([
            'started' => gmdate('Y-m-d\TH:i:s\Z', $stamp),
            'owner' => $owner,
            'baseUrl' => $baseUrl,
            'urls' => $config->urls,
            'seed' => $seed,
            'sample' => $draw,
        ]);

        $io = new CurlProcessIo(self::packageDir(), $runDir, $owner, $config->port, $token, new LogTail());
        $scheduler = new Scheduler(
            $io,
            $record,
            $baseUrl,
            self::archiveUrl($draw),
            fn (): array => $io->logLines(),
            $stderr,
        );

        return self::finish($scheduler->run($selected), $runDir, self::packageDir(), STDOUT);
    }

    /**
     * Writes the run folder when `$exit` is 0.
     *
     * The folder is relative to `$packageDir` when the run lives there.
     *
     * @param resource $stdout
     */
    public static function finish(int $exit, string $runDir, string $packageDir, $stdout): int
    {
        if ($exit !== 0) {
            return $exit;
        }

        $prefix = $packageDir . '/';
        $shown = str_starts_with($runDir, $prefix) ? substr($runDir, strlen($prefix)) : $runDir;
        fwrite($stdout, 'Wrote ' . $shown . "\n");

        return 0;
    }

    /**
     * Writes `$message` to `$stderr` and returns the broken-probe status.
     *
     * @param resource $stderr
     */
    private static function fail($stderr, string $message): int
    {
        fwrite($stderr, $message . "\n");

        return 1;
    }

    private static function packageDir(): string
    {
        return dirname(__DIR__);
    }

    private static function token(): string
    {
        $token = getenv('ELEANOR_BEARER_TOKEN');

        return is_string($token) ? $token : '';
    }

    /**
     * Boots the dev kernel and returns `eleanor.owner_email`.
     *
     * The committed env files supply `kernel.secret`, which `boot()` resolves.
     */
    private static function parameterEmail(): string
    {
        new Dotenv()->bootEnv(self::packageDir() . '/.env');

        $kernel = new Kernel('dev', true);
        $kernel->boot();

        try {
            $email = $kernel->getContainer()->getParameter('eleanor.owner_email');
        } finally {
            $kernel->shutdown();
        }

        if (!is_string($email)) {
            throw new \InvalidArgumentException('The owner email parameter is not a string.');
        }

        return $email;
    }

    /**
     * Returns the draw, an empty list when no selected step needs one, or null when a required draw cannot be made.
     *
     * @param list<string> $selected
     *
     * @return list<string>|null
     */
    private static function draw(Config $config, array $selected, int $seed): ?array
    {
        $needsDraw = false;
        foreach ($selected as $name) {
            if (Catalog::needsDraw($name)) {
                $needsDraw = true;

                break;
            }
        }

        if (!$needsDraw) {
            return [];
        }

        $path = self::packageDir() . '/' . $config->urls;
        if (!is_file($path)) {
            return null;
        }

        $read = file($path, FILE_IGNORE_NEW_LINES);
        if ($read === false) {
            return null;
        }

        $lines = [];
        foreach ($read as $line) {
            $lines[] = $line;
        }

        $draw = Draw::take($lines, $seed, $config->sample);
        if ($draw === []) {
            return null;
        }

        return $draw;
    }

    /**
     * Returns the first drawn line, or an empty string when this run did not draw.
     *
     * @param list<string> $draw
     */
    private static function archiveUrl(array $draw): string
    {
        if ($draw === []) {
            return '';
        }

        return $draw[0];
    }
}
