<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive\Sqlite;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Sqlite\DirectoryException;
use Yumo\Eleanor\Archive\Sqlite\SqliteConnection;
use Yumo\Eleanor\Archive\Sqlite\SqliteSchema;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;

/**
 * Proves that opening a connection creates the database directory and reports a directory failure on its own.
 */
final class SqliteConnectionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sqlite-connection-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        ArchiveDirectories::remove($this->directory);
    }

    public function test_open_creates_missing_parent_directories_owner_only(): void
    {
        $path = $this->directory . '/missing/deeper/ada.sqlite';
        $connection = $this->connection($path);

        $connection->open();
        $connection->close();

        self::assertFileExists($path);
        self::assertSame(0o700, fileperms($this->directory . '/missing') & 0o777);
        self::assertSame(0o700, fileperms($this->directory . '/missing/deeper') & 0o777);
    }

    public function test_open_leaves_an_existing_directory_mode_unchanged(): void
    {
        $parent = $this->directory . '/kept';
        mkdir($parent, 0o755);
        $connection = $this->connection($parent . '/ada.sqlite');

        $connection->open();
        $connection->close();

        self::assertSame(0o755, fileperms($parent) & 0o777);
    }

    public function test_open_fails_when_a_file_stands_where_the_directory_should(): void
    {
        $blocker = $this->directory . '/blocker';
        touch($blocker);
        $path = $blocker . '/ada.sqlite';

        try {
            $this->connection($path)->open();
            self::fail('Expected a DirectoryException.');
        } catch (DirectoryException $exception) {
            self::assertSame('The data directory could not be created.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertFileDoesNotExist($path);
    }

    public function test_open_keeps_the_mkdir_failure_as_the_cause(): void
    {
        $blocker = $this->directory . '/blocker';
        touch($blocker);
        $path = $blocker . '/data/ada.sqlite';
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->connection($path)->open();
            self::fail('Expected a DirectoryException.');
        } catch (DirectoryException $exception) {
            self::assertSame('The data directory could not be created.', $exception->getMessage());
            self::assertInstanceOf(\ErrorException::class, $exception->getPrevious());
        } finally {
            restore_error_handler();
        }

        self::assertFileDoesNotExist($path);
    }

    private function connection(string $path): SqliteConnection
    {
        return new SqliteConnection($path, new SqliteSchema());
    }
}
