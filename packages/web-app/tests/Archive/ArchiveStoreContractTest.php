<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Archive\ArchiveStoreFactory;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\Sqlite\DirectoryException;
use Yumo\Eleanor\Archive\Sqlite\SqliteArchiveStore;
use Yumo\Eleanor\Archive\Sqlite\SqliteArchiveStoreFactory;
use Yumo\Eleanor\Archive\Sqlite\SqliteConnection;
use Yumo\Eleanor\Archive\Sqlite\SqliteSchema;
use Yumo\Eleanor\Archive\UrlPointer;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveStoreFactory;

/**
 * Proves that every archive store keeps and finds one owner's archives the same way.
 */
final class ArchiveStoreContractTest extends TestCase
{
    private const string URL = 'https://example.com/a';
    private const string OTHER_URL = 'https://example.com/b';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/archive-store-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        ArchiveDirectories::remove($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storeKindProvider(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'sqlite' => ['sqlite'];
    }

    #[DataProvider('storeKindProvider')]
    public function test_unknown_url_is_absent(string $kind): void
    {
        $store = $this->factory($kind)->create(new Owner('ada@example.com'));

        self::assertNull($store->find(new UrlPointer(self::URL)));
        self::assertNull($store->findSummary(new UrlPointer(self::URL)));
    }

    #[DataProvider('storeKindProvider')]
    public function test_saved_archive_is_found_whole_and_as_a_summary(string $kind): void
    {
        $store = $this->factory($kind)->create(new Owner('ada@example.com'));
        $archive = new Archive(self::URL, 'Title', 'Excerpt', 'Site', '<p>Body</p>');

        $store->save($archive);

        self::assertEquals($archive, $store->find(new UrlPointer(self::URL)));
        self::assertEquals(new ArchiveSummary(self::URL, 'Title', 'Excerpt', 'Site'), $store->findSummary(new UrlPointer(self::URL)));
    }

    #[DataProvider('storeKindProvider')]
    public function test_absent_excerpt_and_site_name_stay_null(string $kind): void
    {
        $store = $this->factory($kind)->create(new Owner('ada@example.com'));
        $archive = new Archive(self::URL, '', null, null, '<p>Body</p>');

        $store->save($archive);

        self::assertEquals($archive, $store->find(new UrlPointer(self::URL)));
        self::assertEquals(new ArchiveSummary(self::URL, '', null, null), $store->findSummary(new UrlPointer(self::URL)));
    }

    #[DataProvider('storeKindProvider')]
    public function test_second_save_of_one_url_replaces_the_archive(string $kind): void
    {
        $store = $this->factory($kind)->create(new Owner('ada@example.com'));
        $store->save(new Archive(self::URL, 'Old', 'Old excerpt', 'Old site', '<p>Old</p>'));
        $newer = new Archive(self::URL, 'New', null, 'New site', '<p>New</p>');

        $store->save($newer);

        self::assertEquals($newer, $store->find(new UrlPointer(self::URL)));
        self::assertNull($store->find(new UrlPointer(self::OTHER_URL)));
    }

    #[DataProvider('storeKindProvider')]
    public function test_a_nul_wrapped_pointer_finds_the_row_saved_from_the_clean_pointer(string $kind): void
    {
        $store = $this->factory($kind)->create(new Owner('ada@example.com'));
        $archive = new Archive(self::URL, 'Title', 'Excerpt', 'Site', '<p>Body</p>');
        $store->save($archive);
        $wrapped = new UrlPointer("\0" . self::URL . "\0");

        self::assertEquals($archive, $store->find($wrapped));
        self::assertEquals(new ArchiveSummary(self::URL, 'Title', 'Excerpt', 'Site'), $store->findSummary($wrapped));
    }

    #[DataProvider('storeKindProvider')]
    public function test_two_stores_for_one_owner_share_a_row(string $kind): void
    {
        $factory = $this->factory($kind);
        $archive = new Archive(self::URL, 'Title', null, null, '<p>Body</p>');
        $factory->create(new Owner('ada@example.com'))->save($archive);

        $found = $factory->create(new Owner('ada@example.com'))->find(new UrlPointer(self::URL));

        self::assertEquals($archive, $found);
    }

    #[DataProvider('storeKindProvider')]
    public function test_another_owner_does_not_see_the_row(string $kind): void
    {
        $factory = $this->factory($kind);
        $factory->create(new Owner('ada@example.com'))
            ->save(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'));

        $other = $factory->create(new Owner('grace@example.com'));

        self::assertNull($other->find(new UrlPointer(self::URL)));
        self::assertNull($other->findSummary(new UrlPointer(self::URL)));
    }

    public function test_lookup_creates_no_file(): void
    {
        $store = $this->factory('sqlite')->create(new Owner('ada@example.com'));

        $store->findSummary(new UrlPointer(self::URL));
        $store->find(new UrlPointer(self::URL));

        self::assertSame([], $this->databaseFiles());
    }

    public function test_first_save_creates_one_database_file(): void
    {
        $store = $this->factory('sqlite')->create(new Owner('ada@example.com'));

        $store->save(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'));

        self::assertCount(1, $this->databaseFiles());
    }

    public function test_owner_emails_that_differ_in_case_share_one_file(): void
    {
        $factory = $this->factory('sqlite');
        $factory->create(new Owner('Ada@Example.com'))->save(new Archive(self::URL, 'Title', null, null, '<p>A</p>'));

        $factory->create(new Owner('ada@example.com'))->save(new Archive(self::OTHER_URL, 'Title', null, null, '<p>B</p>'));

        self::assertCount(1, $this->databaseFiles());
    }

    public function test_two_owners_leave_two_files(): void
    {
        $factory = $this->factory('sqlite');
        $archive = new Archive(self::URL, 'Title', null, null, '<p>Body</p>');

        $factory->create(new Owner('ada@example.com'))->save($archive);
        $factory->create(new Owner('grace@example.com'))->save($archive);

        self::assertCount(2, $this->databaseFiles());
    }

    public function test_save_creates_a_missing_parent_directory(): void
    {
        $path = $this->directory . '/missing/deeper/ada.sqlite';
        $store = $this->sqliteStore($path);
        $archive = new Archive(self::URL, 'Title', null, null, '<p>Body</p>');

        $store->save($archive);

        self::assertFileExists($path);
        self::assertEquals($archive, $store->find(new UrlPointer(self::URL)));
    }

    public function test_lookup_creates_no_directory(): void
    {
        $path = $this->directory . '/missing/deeper/ada.sqlite';
        $store = $this->sqliteStore($path);

        self::assertNull($store->findSummary(new UrlPointer(self::URL)));
        self::assertNull($store->find(new UrlPointer(self::URL)));

        self::assertDirectoryDoesNotExist($this->directory . '/missing');
        self::assertFileDoesNotExist($path);
    }

    public function test_save_fails_without_a_file_when_the_directory_cannot_be_created(): void
    {
        $blocker = $this->directory . '/blocker';
        touch($blocker);
        $path = $blocker . '/ada.sqlite';
        $store = $this->sqliteStore($path);

        try {
            $store->save(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::NotWritten, $exception->failure);
            $cause = $exception->getPrevious();
            self::assertInstanceOf(DirectoryException::class, $cause);
            self::assertNull($cause->getPrevious());
        }

        self::assertFileDoesNotExist($path);
    }

    public function test_save_of_a_non_database_file_is_not_written(): void
    {
        $path = $this->directory . '/ada.sqlite';
        file_put_contents($path, 'not a database');
        $store = $this->sqliteStore($path);

        try {
            $store->save(new Archive(self::URL, 'Title', null, null, '<p>Body</p>'));
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            self::assertSame(ArchiveFailure::NotWritten, $exception->failure);
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
        }
    }

    public function test_lookup_of_a_non_database_file_is_not_wrapped(): void
    {
        $path = $this->directory . '/ada.sqlite';
        file_put_contents($path, 'not a database');
        $store = $this->sqliteStore($path);

        try {
            $store->findSummary(new UrlPointer(self::URL));
            self::fail('Expected a PDOException.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(\PDOException::class, $exception);
        }

        try {
            $store->find(new UrlPointer(self::URL));
            self::fail('Expected a PDOException.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(\PDOException::class, $exception);
        }
    }

    public function test_second_save_of_one_url_keeps_the_row_id(): void
    {
        $factory = $this->factory('sqlite');
        $store = $factory->create(new Owner('ada@example.com'));
        $store->save(new Archive(self::URL, 'Old', null, null, '<p>Old</p>'));
        $before = $this->rows();

        $store->save(new Archive(self::URL, 'New', null, null, '<p>New</p>'));

        $after = $this->rows();
        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame($before[0]['created_at'], $after[0]['created_at']);
        self::assertSame('New', $after[0]['title']);
        self::assertSame('<p>New</p>', $after[0]['html']);
    }

    public function test_location_names_the_sqlite_file_and_memory_has_none(): void
    {
        $owner = new Owner('ada@example.com');
        $memory = $this->factory('memory')->create($owner);
        $sqlite = $this->factory('sqlite')->create($owner);

        self::assertNull($memory->location());
        self::assertSame(
            $this->directory . '/' . hash('sha256', 'ada@example.com') . '.sqlite',
            $sqlite->location(),
        );
        self::assertSame([], $this->databaseFiles());
    }

    /**
     * Reads the archive rows of the only database file with a connection of its own.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $files = $this->databaseFiles();
        self::assertCount(1, $files);
        $pdo = new \PDO('sqlite:' . $files[0]);
        $statement = $pdo->query('SELECT a.id, a.title, a.created_at, b.html FROM archive a JOIN archive_body b ON b.archive_id = a.id');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function sqliteStore(string $path): SqliteArchiveStore
    {
        return new SqliteArchiveStore(new Owner('ada@example.com'), new SqliteConnection($path, new SqliteSchema()));
    }

    private function factory(string $kind): ArchiveStoreFactory
    {
        if ($kind === 'memory') {
            return new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf());
        }

        return new SqliteArchiveStoreFactory($this->directory);
    }

    /**
     * @return list<string>
     */
    private function databaseFiles(): array
    {
        return glob($this->directory . '/*.sqlite') ?: [];
    }
}
