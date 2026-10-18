<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive\Lock;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Yumo\Eleanor\Archive\Lock\FlockArchiveLock;
use Yumo\Eleanor\Archive\Owner;

/**
 * Proves that the flock lock serializes work on one url and never stops the action from running.
 */
final class FlockArchiveLockTest extends TestCase
{
    private const string URL_A = 'https://example.com/a';
    private const string URL_B = 'https://example.com/b';

    private string $directory;

    /** @var list<resource> */
    private array $children = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flock-archive-lock-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child);
            proc_close($child);
        }
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function test_second_acquire_after_release_does_not_deadlock(): void
    {
        $lock = $this->lock($this->directory . '/locks', new RecordingLogger());

        $first = $lock->exclusive(self::URL_A, static fn (): string => 'first');
        $second = $lock->exclusive(self::URL_A, static fn (): string => 'second');

        self::assertSame('first', $first);
        self::assertSame('second', $second);
    }

    public function test_releases_when_the_action_throws(): void
    {
        $lock = $this->lock($this->directory . '/locks', new RecordingLogger());

        try {
            $lock->exclusive(self::URL_A, $this->failingAction());
            self::fail('The action exception must pass through.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame('after', $lock->exclusive(self::URL_A, static fn (): string => 'after'));
    }

    public function test_different_keys_do_not_block(): void
    {
        $locks = $this->directory . '/locks';
        $release = $this->directory . '/release';
        $this->startChildHolding($locks, self::URL_A, $release);

        $started = hrtime(true);
        $value = $this->lock($locks, new RecordingLogger(), 2000)
            ->exclusive(self::URL_B, static fn (): string => 'b');
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
        file_put_contents($release, '');

        self::assertSame('b', $value);
        self::assertLessThan(1000, $elapsedMilliseconds);
    }

    public function test_one_key_blocks_until_the_first_releases(): void
    {
        $locks = $this->directory . '/locks';
        $this->startChildHolding($locks, self::URL_A, null, 300_000);

        $started = hrtime(true);
        $value = $this->lock($locks, new RecordingLogger(), 5000)
            ->exclusive(self::URL_A, static fn (): string => 'a');
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        self::assertSame('a', $value);
        self::assertGreaterThanOrEqual(200, $elapsedMilliseconds);
    }

    public function test_wait_limit_runs_the_action_without_a_warning(): void
    {
        $locks = $this->directory . '/locks';
        $logger = new RecordingLogger();
        $this->startChildHolding($locks, self::URL_A, $this->directory . '/never-written');

        $value = $this->lock($locks, $logger, 0)
            ->exclusive(self::URL_A, static fn (): string => 'ran');

        self::assertSame('ran', $value);
        self::assertSame([], $logger->warnings);
    }

    public function test_unwritable_lock_directory_logs_and_runs(): void
    {
        $logger = new RecordingLogger();
        $blocker = $this->directory . '/not-a-directory';
        file_put_contents($blocker, '');

        $value = $this->lock($blocker . '/locks', $logger)
            ->exclusive(self::URL_A, static fn (): string => 'ran');

        self::assertSame('ran', $value);
        self::assertSame(['archive.lock.not_taken'], $logger->warnings);
    }

    private function failingAction(): callable
    {
        return static function (): never {
            throw new \RuntimeException('boom');
        };
    }

    private function lock(string $lockDirectory, LoggerInterface $logger, int $waitMilliseconds = 15_000): FlockArchiveLock
    {
        return new FlockArchiveLock(new Owner('ada@example.com'), $lockDirectory, $logger, $waitMilliseconds);
    }

    /**
     * Starts a child process that holds the lock on `$url` and returns once the child has it.
     *
     * The child lets go when `$releaseFile` exists, or after `$holdMicroseconds` if that is given.
     */
    private function startChildHolding(
        string $lockDirectory,
        string $url,
        ?string $releaseFile,
        int $holdMicroseconds = 0,
    ): void {
        $heldFile = $this->directory . '/held-' . bin2hex(random_bytes(4));
        $script = $this->directory . '/child-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, $this->childScript());

        $child = proc_open(
            [PHP_BINARY, $script, dirname(__DIR__, 3) . '/vendor/autoload.php', $lockDirectory, $url, $heldFile, (string) $releaseFile, (string) $holdMicroseconds],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $this->children[] = $child;

        $deadline = hrtime(true) + 10_000_000_000;
        while (!is_file($heldFile)) {
            self::assertLessThan($deadline, hrtime(true), 'The child never took the lock.');
            usleep(10_000);
        }
    }

    private function childScript(): string
    {
        return <<<'PHP'
            <?php
            [, $autoload, $lockDirectory, $url, $heldFile, $releaseFile, $holdMicroseconds] = $argv;
            require $autoload;

            $lock = new Yumo\Eleanor\Archive\Lock\FlockArchiveLock(
                new Yumo\Eleanor\Archive\Owner('ada@example.com'),
                $lockDirectory,
                new Psr\Log\NullLogger(),
            );
            $lock->exclusive($url, static function () use ($heldFile, $releaseFile, $holdMicroseconds): void {
                touch($heldFile);
                if ('' !== $releaseFile) {
                    while (!is_file($releaseFile)) {
                        usleep(10_000);
                    }

                    return;
                }
                usleep((int) $holdMicroseconds);
            });
            PHP;
    }
}

/**
 * Logger double that remembers the message of every warning.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ('warning' === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
