<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveStoreFactory;
use Yumo\Eleanor\Archive\Owner;

/**
 * Makes the SQLite store of one owner. Each owner has one file in the data directory.
 *
 * `$store = $factory->create(new Owner('ada@example.com'));`
 */
final class SqliteArchiveStoreFactory implements ArchiveStoreFactory
{
    /** The schema shared by every owner database this factory opens. */
    private readonly SqliteSchema $schema;

    /**
     * Creates the factory around the directory that holds every owner file.
     */
    public function __construct(
        /** The directory of the owner database files. It is created by the first save. */
        private string $dataDirectory,
    ) {
        $this->schema = new SqliteSchema();
    }

    /**
     * Returns the store for `$owner`. The file is named by the SHA-256 hash of the owner email.
     */
    public function create(Owner $owner): ArchiveStore
    {
        $path = $this->dataDirectory . '/' . hash('sha256', $owner->email) . '.sqlite';

        return new SqliteArchiveStore($owner, new SqliteConnection($path, $this->schema));
    }
}
