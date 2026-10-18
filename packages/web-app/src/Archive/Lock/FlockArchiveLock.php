<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Lock;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Yumo\Eleanor\Archive\ArchiveLock;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Serializes work on one url of one owner with a flock file lock, across processes.
 *
 * A caller that waits too long, or that finds the lock directory unusable, runs its action
 * anyway. The lock is a courtesy that avoids duplicate work, not a gate.
 *
 * `$lock = new FlockArchiveLock($owner, '/var/lock', $logger);`
 */
#[Exclude]
final class FlockArchiveLock implements ArchiveLock
{
    /** Pause in milliseconds between two tries to take a busy lock. */
    private const int POLL_MILLISECONDS = 50;

    /**
     * Creates a lock for `$owner` that waits at most `$waitMilliseconds` for a busy url.
     */
    public function __construct(
        /** The owner whose email is part of every key. */
        private Owner $owner,
        /** The directory that holds the lock files. It is created when missing. */
        private string $lockDirectory,
        /** The logger that hears about locks that could not be taken. */
        private LoggerInterface $logger,
        /** How long to wait for a busy url before running the action without the lock. */
        private int $waitMilliseconds = 15_000,
    ) {
    }

    public function exclusive(UrlPointer $url, callable $action): mixed
    {
        $held = $this->take($url);

        try {
            return $action();
        } finally {
            $this->release($held);
        }
    }

    /**
     * Lets go of `$held`. A failed release is logged and swallowed, so it cannot replace the action's outcome.
     */
    private function release(?LockInterface $held): void
    {
        try {
            $held?->release();
        } catch (\Throwable $exception) {
            $this->logger->warning('archive.lock.not_released', ['exception' => $exception]);
        }
    }

    /**
     * Returns the lock when it was taken in time, or null when the wait ran out or the store failed.
     */
    private function take(UrlPointer $url): ?LockInterface
    {
        try {
            // No auto-release: `exclusive` releases once, and a destructor must not retry a failed release.
            $lock = $this->factory()->createLock($this->owner->email . "\0" . $url->toString(), autoRelease: false);

            return $this->acquireWithin($lock) ? $lock : null;
        } catch (\Throwable $exception) {
            $this->logger->warning('archive.lock.not_taken', ['exception' => $exception]);

            return null;
        }
    }

    private function acquireWithin(LockInterface $lock): bool
    {
        $deadline = hrtime(true) + $this->waitMilliseconds * 1_000_000;

        while (!$lock->acquire()) {
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(self::POLL_MILLISECONDS * 1000);
        }

        return true;
    }

    private function factory(): LockFactory
    {
        return new LockFactory(new FlockStore($this->lockDirectory));
    }
}
