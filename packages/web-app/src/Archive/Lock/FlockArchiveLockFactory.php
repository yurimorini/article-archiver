<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Lock;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Yumo\Eleanor\Archive\ArchiveLockFactory;
use Yumo\Eleanor\Archive\Owner;

/**
 * Builds flock locks that keep their files in one shared directory.
 *
 * `$lock = $factory->create(new Owner('ada@example.com'));`
 */
#[WithMonologChannel('application')]
final class FlockArchiveLockFactory implements ArchiveLockFactory
{
    /**
     * Creates the factory from the directory of the lock files and the logger the locks share.
     */
    public function __construct(
        /** The directory that holds the lock files. */
        private string $lockDirectory,
        /** The logger that hears about locks that could not be taken. */
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns a lock for `$owner` that waits at most the default time.
     */
    public function create(Owner $owner): FlockArchiveLock
    {
        return new FlockArchiveLock($owner, $this->lockDirectory, $this->logger);
    }
}
