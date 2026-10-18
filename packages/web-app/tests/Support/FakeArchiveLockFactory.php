<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Archive\ArchiveLock;
use Yumo\Eleanor\Archive\ArchiveLockFactory;
use Yumo\Eleanor\Archive\Owner;

/**
 * Makes a fake lock for every owner. It keeps no state.
 */
final class FakeArchiveLockFactory implements ArchiveLockFactory
{
    public function create(Owner $owner): ArchiveLock
    {
        return new FakeArchiveLock();
    }
}
