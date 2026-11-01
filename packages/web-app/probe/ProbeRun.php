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
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        try {
            $config = Config::fromFile(__DIR__ . '/probe.json');
            $selected = Selection::names($config->steps, $argv);
            $token = self::token();
            Guard::token($token);
            $owner = Guard::owner(self::parameterEmail(), $config->owner);
            $seed = $config->seed ?? random_int(1, PHP_INT_MAX);
            $draw = self::draw($config, $selected, $seed);
        } catch (\InvalidArgumentException) {
            return 1;
        }

        if ($draw === null) {
            return 1;
        }

        $stamp = time();
        $runDir = self::packageDir() . '/probe/runs/' . gmdate('Ymd\THis\Z', $stamp) . '-' . $seed;
        if (!is_dir($runDir) && !mkdir($runDir, 0o775, true)) {
            return 1;
        }

        if ($draw !== []) {
            $written = file_put_contents($runDir . '/sample.txt', implode("\n", $draw) . "\n");
            if ($written === false) {
                return 1;
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
        );

        return $scheduler->run($selected);
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
