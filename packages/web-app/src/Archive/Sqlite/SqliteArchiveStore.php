<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive\Sqlite;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveDirection;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchivePageSlice;
use Yumo\Eleanor\Archive\ArchiveSort;
use Yumo\Eleanor\Archive\ArchiveStore;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\UrlPointer;

/**
 * Keeps one owner's archives in one SQLite file.
 *
 * A lookup never creates the file. The first `save` opens the connection, which creates the file and its directory.
 * `save` throws `ArchiveException` with `NotWritten`. `findPage` throws `ArchiveException` with `Unexpected`.
 * `find` and `findSummary` are not wrapped. The connection still raises `DirectoryException` and `\PDOException`.
 *
 * `$store = new SqliteArchiveStore($owner, new SqliteConnection('/data/ada.sqlite', new SqliteSchema()));`
 */
#[Exclude]
final class SqliteArchiveStore implements ArchiveStore
{
    /**
     * Creates a store for `$owner` that reads and writes through `$connection`.
     */
    public function __construct(
        /** The owner whose archives this store reads and writes. */
        private Owner $owner,
        /** The connection for this owner's database file. */
        private SqliteConnection $connection,
    ) {
    }

    /**
     * Returns the light fields this owner stored for `$url`, or null when there is none.
     *
     * A failure is not wrapped.
     */
    public function findSummary(UrlPointer $url): ?ArchiveSummary
    {
        $row = $this->fetchRow(
            'SELECT id, url, title, excerpt, site_name, created_at FROM archive WHERE url = :url',
            $url->toString(),
        );
        if ($row === null) {
            return null;
        }

        return new ArchiveSummary(
            $row['url'],
            $row['title'],
            $row['excerpt'],
            $row['site_name'],
            (int) $row['id'],
            (string) $row['created_at'],
        );
    }

    /**
     * Returns the archive this owner stored for `$url`, or null when there is none.
     *
     * A failure is not wrapped.
     */
    public function find(UrlPointer $url): ?Archive
    {
        $row = $this->fetchRow(
            'SELECT a.id, a.url, a.title, a.excerpt, a.site_name, a.created_at, b.html'
            . ' FROM archive a JOIN archive_body b ON b.archive_id = a.id WHERE a.url = :url',
            $url->toString(),
        );
        if ($row === null) {
            return null;
        }

        return new Archive(
            $row['url'],
            $row['title'],
            $row['excerpt'],
            $row['site_name'],
            $row['html'],
            (int) $row['id'],
            (string) $row['created_at'],
        );
    }

    /**
     * Returns one page of this owner's archives and the count of every archive.
     *
     * A missing file is an empty page and is not created. Rows are ordered by `created_at`, then by id.
     * The sort token `archived` is the `created_at` column.
     *
     * @throws ArchiveException When the page cannot be read, with `Unexpected`
     * @throws OwnerFileException When the file belongs to another owner
     */
    public function findPage(ArchivePageQuery $query): ArchivePageSlice
    {
        if (!is_file($this->connection->path)) {
            return new ArchivePageSlice([], 0);
        }

        try {
            return $this->withConnection(function (\PDO $pdo) use ($query): ArchivePageSlice {
                return $this->readPage($pdo, $query);
            });
        } catch (OwnerFileException $error) {
            // The owner check stays a refusal. It is not an archive read failure.
            throw $error;
        } catch (\Throwable $error) {
            throw new ArchiveException(ArchiveFailure::Unexpected, $error);
        }
    }

    /**
     * Stores `$archive` under its URL and returns the stored archive.
     *
     * A second save of the same URL replaces the title, excerpt, site name, and HTML, and keeps the id and the insert time.
     * The id on `$archive` is not written. The insert time is the row's `created_at` and is not changed on conflict.
     *
     * @throws ArchiveException When the archive cannot be stored, with `NotWritten`
     */
    public function save(Archive $archive): Archive
    {
        try {
            return $this->withConnection(function (\PDO $pdo) use ($archive): Archive {
                try {
                    $pdo->beginTransaction();
                    $this->writeOwner($pdo);
                    [$id, $archived] = $this->writeArchive($pdo, $archive);
                    $this->writeBody($pdo, $id, $archive->html);
                    $pdo->commit();

                    return new Archive(
                        $archive->url,
                        $archive->title,
                        $archive->excerpt,
                        $archive->siteName,
                        $archive->html,
                        $id,
                        $archived,
                    );
                } catch (\Throwable $failure) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $failure;
                }
            });
        } catch (DirectoryException $error) {
            throw new ArchiveException(ArchiveFailure::NotWritten, $error);
        } catch (\PDOException $error) {
            throw new ArchiveException(ArchiveFailure::NotWritten, $error);
        }
    }

    /**
     * Reads one page and the count of every archive.
     *
     * The order words come from `$query`, not from a query string.
     *
     * @throws \PDOException When the page cannot be read
     */
    private function readPage(\PDO $pdo, ArchivePageQuery $query): ArchivePageSlice
    {
        $column = match ($query->sort) {
            ArchiveSort::Archived => 'created_at',
        };
        $direction = match ($query->dir) {
            ArchiveDirection::Asc => 'ASC',
            ArchiveDirection::Desc => 'DESC',
        };
        $offset = ($query->page - 1) * $query->size;
        $statement = $pdo->query(
            'SELECT id, url, title, excerpt, site_name, created_at FROM archive'
            . ' ORDER BY ' . $column . ' ' . $direction . ', id ' . $direction
            . ' LIMIT ' . $query->size . ' OFFSET ' . $offset,
        );
        if ($statement === false) {
            throw new \PDOException('The archive page could not be read.');
        }

        $items = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $items[] = new ArchiveSummary(
                $row['url'],
                $row['title'],
                $row['excerpt'],
                $row['site_name'],
                (int) $row['id'],
                (string) $row['created_at'],
            );
        }

        $count = $pdo->query('SELECT COUNT(*) FROM archive');
        if ($count === false) {
            throw new \PDOException('The archive count could not be read.');
        }

        return new ArchivePageSlice($items, (int) $count->fetchColumn());
    }

    /**
     * Runs a one-row lookup by URL and returns the row, or null when the file or the row is missing.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $sql, string $url): ?array
    {
        if (!is_file($this->connection->path)) {
            return null;
        }

        $row = $this->withConnection(function (\PDO $pdo) use ($sql, $url): array|false {
            $statement = $pdo->prepare($sql);
            $statement->execute(['url' => $url]);

            return $statement->fetch(\PDO::FETCH_ASSOC);
        });

        return $row === false ? null : $row;
    }

    /**
     * Opens this owner's file, runs `$use`, and closes the connection.
     *
     * @template T
     *
     * @param callable(\PDO): T $use
     *
     * @return T
     */
    private function withConnection(callable $use): mixed
    {
        try {
            $pdo = $this->connection->open();
            $this->assertOwner($pdo);

            return $use($pdo);
        } finally {
            $this->connection->close();
        }
    }

    /**
     * Stops when the file already names a different owner.
     *
     * A missing email row is the first write, which inserts the email.
     *
     * @throws OwnerFileException When `meta.email` is present and is not this owner
     */
    private function assertOwner(\PDO $pdo): void
    {
        $statement = $pdo->query("SELECT value FROM meta WHERE key = 'email'");
        if ($statement === false) {
            throw new OwnerFileException('The owner email could not be read.');
        }
        $email = $statement->fetchColumn();
        if ($email === false || $email === $this->owner->email) {
            return;
        }

        throw new OwnerFileException('The database file belongs to another owner.');
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

    /**
     * Inserts or updates the archive row and returns its id and insert time.
     *
     * The insert time is the column default. A conflict does not assign `created_at`.
     *
     * @return array{int, string}
     */
    private function writeArchive(\PDO $pdo, Archive $archive): array
    {
        $statement = $pdo->prepare(
            'INSERT INTO archive (url, title, excerpt, site_name)'
            . ' VALUES (:url, :title, :excerpt, :site_name)'
            . ' ON CONFLICT(url) DO UPDATE SET'
            . ' title = excluded.title, excerpt = excluded.excerpt, site_name = excluded.site_name'
            . ' RETURNING id, created_at',
        );
        $statement->execute([
            'url' => $archive->url,
            'title' => $archive->title,
            'excerpt' => $archive->excerpt,
            'site_name' => $archive->siteName,
        ]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        if (!is_array($row)) {
            throw new \PDOException('The stored archive row could not be read.');
        }

        return [(int) $row[0], (string) $row[1]];
    }

    private function writeBody(\PDO $pdo, int $id, string $html): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO archive_body (archive_id, html) VALUES (:id, :html)'
            . ' ON CONFLICT(archive_id) DO UPDATE SET html = excluded.html',
        );
        $statement->execute(['id' => $id, 'html' => $html]);
    }

    /**
     * Returns the path of this owner's database file.
     *
     * The file is not opened and is not created.
     */
    public function location(): string
    {
        return $this->connection->path;
    }
}
