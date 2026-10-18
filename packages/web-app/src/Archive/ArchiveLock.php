<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Lets one caller at a time work on the archive of one url.
 *
 * `$value = $lock->exclusive($url, fn () => $store->find($url));`
 */
interface ArchiveLock
{
    /**
     * Runs `$action` while holding the lock on `$url` and returns what it returns.
     *
     * The lock is released when `$action` returns and when it throws. If the lock cannot be
     * taken, `$action` still runs.
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function exclusive(string $url, callable $action): mixed;
}
