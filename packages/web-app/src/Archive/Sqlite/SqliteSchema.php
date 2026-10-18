<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Brings the tables of one owner database up to the current schema version.
 *
 * The version lives in `PRAGMA user_version`. A new database has version 0.
 *
 * `new SqliteSchema()->migrate($pdo);`
 */
#[Exclude]
final class SqliteSchema
{
    private const string VERSION_1 = <<<'SQL'
        CREATE TABLE meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE archive (
            id INTEGER PRIMARY KEY,
            url TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            excerpt TEXT,
            site_name TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );
        CREATE TABLE archive_body (
            archive_id INTEGER PRIMARY KEY REFERENCES archive(id) ON DELETE CASCADE,
            html TEXT NOT NULL
        );
        PRAGMA user_version = 1;
        SQL;

    /**
     * Creates the version 1 tables when the database is new, and leaves a version 1 database as it is.
     */
    public function migrate(\PDO $pdo): void
    {
        if ($this->version($pdo) >= 1) {
            return;
        }

        // Another process may create the tables first, so the version is read again once the write lock is held.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->version($pdo) < 1) {
                $pdo->exec(self::VERSION_1);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            $pdo->exec('ROLLBACK');
            throw $failure;
        }
    }

    /**
     * Reads the version from the database, so a second call can differ from the first.
     *
     * @phpstan-impure
     */
    private function version(\PDO $pdo): int
    {
        $statement = $pdo->query('PRAGMA user_version');
        if ($statement === false) {
            throw new \PDOException('The schema version could not be read.');
        }

        return (int) $statement->fetchColumn();
    }
}
