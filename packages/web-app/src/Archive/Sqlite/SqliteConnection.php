<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Opens one owner database file with the settings every store needs.
 *
 * `$pdo = new SqliteConnection(new SqliteSchema())->open($path);`
 */
#[Exclude]
final class SqliteConnection
{
    private ?\PDO $pdo = null;

    /**
     * Creates the connection around the schema that prepares each database it opens.
     */
    public function __construct(
        /** The schema that brings a new database up to the current version. */
        private readonly SqliteSchema $schema,
    ) {
    }

    /**
     * Opens the database file at `$path`, creating it when it is missing, and returns the connection.
     *
     * Errors raise `\PDOException`.
     */
    public function open(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA page_size = 8192');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $this->schema->migrate($pdo);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo = $pdo;

        return $pdo;
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
