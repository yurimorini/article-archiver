<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Opens one owner database file with the settings every store needs.
 *
 * `$pdo = (new SqliteConnection($path, new SqliteSchema()))->open();`
 */
#[Exclude]
final class SqliteConnection
{
    private ?\PDO $pdo = null;

    /**
     * Creates the connection for the database file at `$path`.
     */
    public function __construct(
        /** The path of the database file. The store reads it to see whether the file already exists. */
        public readonly string $path,
        /** The schema that brings a new database up to the current version. */
        private readonly SqliteSchema $schema,
    ) {
    }

    /**
     * Opens the database file at `$path`, creating it when it is missing, and returns the connection.
     *
     * A missing parent directory is created first, at mode `0700`. That failure raises `DirectoryException`. A database failure raises `\PDOException`.
     */
    public function open(): \PDO
    {
        $this->ensureDirectory();
        $pdo = new \PDO('sqlite:' . $this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA page_size = 8192');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $this->schema->migrate($pdo);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * Creates the parent directory of the database file when it is missing.
     *
     * An existing directory is left as it is.
     *
     * @throws DirectoryException
     */
    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            return;
        }

        // A file in the place of the directory cannot be fixed by mkdir, which would only warn.
        if (file_exists($directory)) {
            throw new DirectoryException('The data directory could not be created.');
        }

        $error = null;
        try {
            // 0700 stays owner-only under any umask, because a umask only clears bits.
            $created = mkdir($directory, 0o700, true);
        } catch (\Throwable $caught) {
            $error = $caught;
            $created = false;
        }

        // Another process may have created the directory between the check and the mkdir.
        if (!$created && !is_dir($directory)) {
            throw new DirectoryException('The data directory could not be created.', 0, $error);
        }
    }

    /**
     * Closes the connection that `open` returned. It does nothing when none is open.
     */
    public function close(): void
    {
        if ($this->pdo === null) {
            return;
        }

        // PDO has no close method. The connection ends when the last reference to it is gone.
        $this->pdo = null;
    }
}
