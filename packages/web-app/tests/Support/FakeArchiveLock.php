<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Archive\ArchiveLock;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Runs the action at once, records the URL, and writes `enter` and `leave` around the action.
 *
 * One mode does not hold the lock. It still runs the action, as the real lock does when it cannot take the file.
 */
final class FakeArchiveLock implements ArchiveLock
{
    /** @var list<string> The URLs `exclusive` was called with, in order. */
    public array $urls = [];

    /** Whether the last call held the lock. Null before the first call. */
    public ?bool $held = null;

    /**
     * Creates a lock that writes to `$log`. `$holds` false makes it run the action without the lock.
     *
     * @param \ArrayObject<int, string> $log
     */
    public function __construct(
        /** The log that the lock, the fetcher, and the store share. */
        private \ArrayObject $log = new \ArrayObject(),
        /** Whether the lock is taken. */
        private bool $holds = true,
    ) {
    }

    public function exclusive(UrlPointer $url, callable $action): mixed
    {
        $this->urls[] = $url->toString();
        $this->held = $this->holds;
        if (!$this->holds) {
            return $action();
        }

        $this->log->append('enter');
        try {
            return $action();
        } finally {
            $this->log->append('leave');
        }
    }
}
