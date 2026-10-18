<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;

/**
 * Keeps one owner's archives in one SQLite file.
 *
 * A lookup never creates the file. The first `save` creates the file and its directory.
 * Database errors raise `\PDOException`.
 *
 * `$store = new SqliteArchiveStore($owner, '/data/ada.sqlite');`
 */
#[Exclude]
final class SqliteArchiveStore implements ArchiveStore
{
    /**
     * Creates a store for `$owner` that keeps its rows in the file at `$path`.
     */
    public function __construct(
        /** The owner whose archives this store reads and writes. */
        private Owner $owner,
        /** The path of the database file. */
        private string $path,
    ) {
    }

    /**
     * Returns the light fields this owner stored for `$url`, or null when there is none.
     */
    public function findSummary(string $url): ?ArchiveSummary
    {
        $row = $this->fetchRow('SELECT url, title, excerpt, site_name FROM archive WHERE url = :url', $url);
        if ($row === null) {
            return null;
        }

        return new ArchiveSummary($row['url'], $row['title'], $row['excerpt'], $row['site_name']);
    }

    /**
     * Returns the archive this owner stored for `$url`, or null when there is none.
     */
    public function find(string $url): ?Archive
    {
        $row = $this->fetchRow(
            'SELECT a.url, a.title, a.excerpt, a.site_name, b.html'
            . ' FROM archive a JOIN archive_body b ON b.archive_id = a.id WHERE a.url = :url',
            $url,
        );
        if ($row === null) {
            return null;
        }

        return new Archive($row['url'], $row['title'], $row['excerpt'], $row['site_name'], $row['html']);
    }

    /**
     * Stores `$archive` under its URL. A second save of the same URL replaces the title, excerpt, site name and HTML.
     */
    public function save(Archive $archive): void
    {
        $this->ensureDirectory();
        $connection = new SqliteConnection(new SqliteSchema());
        $pdo = $connection->open($this->path);

        try {
            $pdo->beginTransaction();
            $this->writeOwner($pdo);
            $id = $this->writeArchive($pdo, $archive);
            $this->writeBody($pdo, $id, $archive->html);
            $pdo->commit();
        } catch (\Throwable $failure) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $failure;
        } finally {
            $connection->close();
        }
    }

    /**
     * Runs a one-row lookup by URL and returns the row, or null when the file or the row is missing.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $sql, string $url): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $connection = new SqliteConnection(new SqliteSchema());
        $pdo = $connection->open($this->path);

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute(['url' => $url]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } finally {
            $connection->close();
        }

        return $row === false ? null : $row;
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            return;
        }

        // A file in the place of the directory cannot be fixed by mkdir, which would only warn.
        if (file_exists($directory)) {
            throw new \PDOException('The data directory could not be created.');
        }

        $error = null;
        try {
            $created = mkdir($directory, 0o777, true);
        } catch (\Throwable $caught) {
            $error = $caught;
            $created = false;
        }

        // Another process may have created the directory between the check and the mkdir.
        if (!$created && !is_dir($directory)) {
            throw new \PDOException('The data directory could not be created.', 0, $error);
        }
    }

    private function writeOwner(\PDO $pdo): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO meta (key, value) VALUES ('email', :email)"
            . ' ON CONFLICT(key) DO NOTHING',
        );
        $statement->execute(['email' => $this->owner->email]);
        $pdo->exec(
            "INSERT INTO meta (key, value) VALUES ('created_at', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))"
            . ' ON CONFLICT(key) DO NOTHING',
        );
    }

    private function writeArchive(\PDO $pdo, Archive $archive): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO archive (url, title, excerpt, site_name)'
            . ' VALUES (:url, :title, :excerpt, :site_name)'
            . ' ON CONFLICT(url) DO UPDATE SET'
            . ' title = excluded.title, excerpt = excluded.excerpt, site_name = excluded.site_name'
            . ' RETURNING id',
        );
        $statement->execute([
            'url' => $archive->url,
            'title' => $archive->title,
            'excerpt' => $archive->excerpt,
            'site_name' => $archive->siteName,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function writeBody(\PDO $pdo, int $id, string $html): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO archive_body (archive_id, html) VALUES (:id, :html)'
            . ' ON CONFLICT(archive_id) DO UPDATE SET html = excluded.html',
        );
        $statement->execute(['id' => $id, 'html' => $html]);
    }
}
