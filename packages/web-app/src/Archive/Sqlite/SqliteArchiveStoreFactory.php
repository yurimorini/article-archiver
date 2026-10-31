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
     * Returns the store for `$owner`. The file name is the first 16 hexadecimal characters of the SHA-256 of the owner email.
     *
     * A file left under the full hash is renamed to that name, with its `-wal` and `-shm` siblings.
     * When both names exist, neither file is opened.
     *
     * @throws OwnerFileException When the short name and the full hash name are both present, or the full hash file cannot be renamed
     */
    public function create(Owner $owner): ArchiveStore
    {
        $hash = hash('sha256', $owner->email);
        $shortPath = $this->dataDirectory . '/' . substr($hash, 0, 16) . '.sqlite';
        $fullPath = $this->dataDirectory . '/' . $hash . '.sqlite';
        if (is_file($shortPath) && is_file($fullPath)) {
            throw new OwnerFileException('The owner database has both the short name and the full hash name.');
        }
        if (!is_file($shortPath) && is_file($fullPath)) {
            $this->renameFullHash($fullPath, $shortPath);
        }

        return new SqliteArchiveStore($owner, new SqliteConnection($shortPath, $this->schema));
    }

    /**
     * Moves `$from` and its WAL siblings onto `$to`.
     *
     * @throws OwnerFileException When a present file cannot be renamed
     */
    private function renameFullHash(string $from, string $to): void
    {
        foreach (['-wal', '-shm', ''] as $suffix) {
            if (!is_file($from . $suffix)) {
                continue;
            }
            if (!rename($from . $suffix, $to . $suffix)) {
                throw new OwnerFileException('The owner database could not be renamed.');
            }
        }
    }
}
