<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Builds the lock of one owner.
 *
 * `$lock = $factory->create(new Owner('ada@example.com'));`
 */
interface ArchiveLockFactory
{
    /**
     * Returns a lock whose keys belong to `$owner`.
     */
    public function create(Owner $owner): ArchiveLock;
}
