# SQLite store

Module design for [sqlite-store.md](../sqlite-store.md). The decisions in that contract are closed. This spec adopts [adr/0003](../../../adr/0003-owner-database-is-wal.md) and [adr/0008](../../../adr/0008-owner-database-schema-splits-body-from-metadata.md). It revises [ArchiveCommand.md](ArchiveCommand.md). Revised by [UrlPointer.md](UrlPointer.md): the service no longer trims the URL, and the lock, the fetch, and the store take a `UrlPointer`. The glossary already says that two addresses that differ only in letter case name the same owner. This slice adds no glossary term.

Folder layout follows the rule in [project-foundation](../../../../../../.agents/rules/project-foundation.md). This spec lists only the folders this slice opens. That list is not a template for a later slice.

## Revises

[ArchiveCommand.md](ArchiveCommand.md) closed this: `Owner` keeps the email it is given, `ArchiveResult` carries an `Archive`, `ArchiveStore` has `find` and `save`, `save` is not an upsert, `ArchiveService` calls `find` and holds no lock, and the in-memory store lives in `src/`. This slice replaces those. The edits to that spec are part of accepting this one.

## Module

`src/Archive/` keeps the types and the ports the rest of the app names. Two adapter folders open because each has more than one class and can be replaced.

```text
Archive/
  Owner.php
  Archive.php
  ArchiveSummary.php
  ArchiveOutcome.php
  ArchiveResult.php
  ArchiveException.php
  ArchiveFailure.php
  ArchiveFailureMapper.php
  ArchiveService.php
  ArchiveServiceFactory.php
  ArchiveStore.php
  ArchiveStoreFactory.php
  ArchiveLock.php
  ArchiveLockFactory.php
  ArticleFetcher.php
  ArticleReaderAdapter.php
  Sqlite/
    SqliteArchiveStore.php
    SqliteArchiveStoreFactory.php
    SqliteConnection.php
    SqliteSchema.php
  Lock/
    FlockArchiveLock.php
    FlockArchiveLockFactory.php
```

`Failure/` and `Fetch/` stay closed. `ArticleReaderAdapter` is one class beside its port. `Api` already imports `ArchiveFailure` and `ArchiveException` from the archive namespace.

### `Yumo\Eleanor\Archive`

- `Owner`: the constructor stores `mb_strtolower($email, 'UTF-8')`. Dots and `+` tags stay. `#[Exclude]`.
- `ArchiveSummary`: final readonly class. Public `string $url`, `string $title`, `?string $excerpt`, `?string $siteName`. No HTML. `fromArchive(Archive $archive): self` copies those fields and leaves the HTML out. `#[Exclude]`.
- `ArchiveResult`: public `ArchiveSummary $summary` and `ArchiveOutcome $outcome`. `#[Exclude]`.
- `ArchiveStore`: `findSummary(UrlPointer $url): ?ArchiveSummary`, `find(UrlPointer $url): ?Archive`, `save(Archive $archive): void` (revised by [UrlPointer.md](UrlPointer.md); before that revision the lookup methods took `string $url`). A store belongs to one owner. No method takes the owner. `save` is an upsert.
- `ArchiveLock`: `exclusive(UrlPointer $url, callable $action): mixed` (revised by [UrlPointer.md](UrlPointer.md); before that revision the URL was a string). The callable takes no arguments. The method runs it and returns its result. The lock is released when the callable returns or throws. If the lock cannot be taken, the callable still runs. The interface does not name Symfony.
- `ArchiveLockFactory`: `create(Owner $owner): ArchiveLock`.
- `ArchiveService`: `#[Exclude]`. The constructor is `ArchiveStore`, `ArticleFetcher`, `ArchiveLock`, then `ArchiveFailureMapper` with the same default as today. It does not hold the `Owner`. The store and the lock are already bound to that owner.
- `ArchiveServiceFactory`: the constructor takes `ArticleFetcher`, `ArchiveStoreFactory`, and `ArchiveLockFactory`. `create(Owner)` returns a service with that owner's store and that owner's lock.

`ArchiveService::archive(UrlPointer $url): ArchiveResult` (revised by [UrlPointer.md](UrlPointer.md); before that revision the argument was a string and this method trimmed it):

1. The pointer already holds the stored name. This method does not trim.
2. `exclusive($url)` around the steps below. The service passes the pointer only. The lock already holds the owner and builds the key as the email, a NUL byte, and `$url->toString()`.
3. `findSummary`. A hit returns `AlreadyStored` with that summary. The fetcher is not called. The HTML is not read.
4. A miss fetches. Library failures become `ArchiveException` as slice 6 left them. Any other throwable passes through. Nothing is saved. The lock is released.
5. `PipelineNoContent` becomes `ArchiveException`. Nothing is saved.
6. `PipelineSuccess` becomes `Archive`. `url` is `$url->toString()`. The other fields stay as slice 5 and slice 6 left them.
7. `save`. A `PDOException` becomes `ArchiveException` with `ArchiveFailure::NotWritten` and that exception as the cause. The summary of `Written` is built from the `Archive` just saved. The row is not read back.

`https://example.com/a` and `https://example.com/a/` stay two archives. A posted `\0https://example.com/a` is the same archive as `https://example.com/a`.

### `Yumo\Eleanor\Archive\Sqlite`

- `SqliteArchiveStore`: implements `ArchiveStore`, `#[Exclude]`. The constructor takes the owner and the path of that owner's file. `findSummary` and `find` return null without opening the file when the file does not exist, so PDO does not create it. `save` creates the file when it is missing.
- `SqliteArchiveStoreFactory`: implements `ArchiveStoreFactory`. The constructor takes the data directory. The file name is `hash('sha256', $owner->email) . '.sqlite'`.
- `SqliteConnection`: opens the PDO, applies the pragmas below, asks `SqliteSchema` to migrate, and closes when the store method returns.
- `SqliteSchema`: the only migration class. It reads `PRAGMA user_version`. Version 0 runs the version 1 statements and sets the version to 1. Version 1 does nothing. A later version is another block in this class, not a new class per version.

### `Yumo\Eleanor\Archive\Lock`

- `FlockArchiveLock`: implements `ArchiveLock`, `#[Exclude]`. The constructor takes the owner, the lock directory, the `application` logger, and the wait limit. It builds the key from the email, a NUL byte, and `$url->toString()` (revised by [UrlPointer.md](UrlPointer.md)). It uses `symfony/lock` with `FlockStore`. The wait limit defaults to 15 seconds, so a test can pass a short one. The plan chooses the Symfony retry store or a local retry loop. That choice has to enforce the limit. Symfony's blocking acquire alone does not.
- `FlockArchiveLockFactory`: implements `ArchiveLockFactory`. The constructor takes the lock directory and the `application` logger. An I/O failure while taking the lock logs a warning on that channel and then runs the action. The warning does not include a client response. When the wait limit passes, the action runs with no warning and no HTTP error.

The in-memory shelf, store, and factory move to `tests/Support/InMemory/` under `Yumo\Eleanor\Tests\Support\InMemory`. They gain `findSummary`. They are the contract-test double. They are not container services.

### `Yumo\Eleanor\Api`

`CreateArchiveController` builds `new UrlPointer` from the body string and passes that to `archive` (revised by [UrlPointer.md](UrlPointer.md)). It reads `$result->summary->url` and `$result->summary->title`. The route, the status map, and the `Reply` stay. The body DTO stays a string.

## Schema and file

One file per owner, under `eleanor.data_dir`. `meta` holds two keys. `email` is `$owner->email`. `created_at` is the creation time of the file, written once.

On every open, `SqliteConnection` does this in order:

1. `PRAGMA page_size = 8192`, before any table. SQLite ignores it once the file has pages.
2. `PRAGMA foreign_keys = ON` and `PRAGMA busy_timeout = 5000`.
3. `SqliteSchema` migrates.
4. `PRAGMA journal_mode = WAL`, after the tables exist. On a file that is already WAL, this changes nothing.

Version 1:

- `meta(key TEXT PRIMARY KEY, value TEXT NOT NULL)`
- `archive(id INTEGER PRIMARY KEY, url TEXT NOT NULL UNIQUE, title TEXT NOT NULL, excerpt TEXT, site_name TEXT, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')))`
- `archive_body(archive_id INTEGER PRIMARY KEY REFERENCES archive(id) ON DELETE CASCADE, html TEXT NOT NULL)`

`created_at` is UTC. SQLite's `now` is UTC.

`save` runs one transaction. It upserts `archive` with `ON CONFLICT(url) DO UPDATE` of `title`, `excerpt`, and `site_name`. `id` and `created_at` stay. It is not `INSERT OR REPLACE`. It then upserts `archive_body` on that same `id`. When `meta` has no `email` row, the same transaction inserts `email` and `created_at`.

`findSummary` reads `archive` only. `find` joins `archive_body`.

A `save` that fails after the file has been created can leave the schema with no archive row. A later lookup does not create a file. A later `save` of that owner writes into the file that is already there.

The development image has SQLite 3.40.1. The WAL-reset bug, present from 3.7.0 through 3.51.2, is fixed in 3.51.3. This slice does not upgrade the image. [Clean distribution image](../../../backlog/clean-distribution-image.md) owns that upgrade.

## Configuration

`config/services.yaml`:

- `eleanor.data_dir` is `%kernel.project_dir%/var/data`.
- `eleanor.lock_dir` is `%kernel.project_dir%/var/lock`.
- `SqliteArchiveStoreFactory` takes the data directory. `FlockArchiveLockFactory` takes the lock directory. Each port has one implementation in `src/`, so the aliases stay automatic.
- The first `save` creates the data directory when it is missing. A data directory that cannot be created fails the `save`. A lock directory that cannot be created, or that is read-only, is the lock I/O warning above. The archive still proceeds.

`composer.json` gains `symfony/lock` at `8.1.*` and `ext-pdo_sqlite`. The image already has SQLite 3.40.1.

`config/services_test.yaml` maps `eleanor.data_dir` to `ELEANOR_DATA_DIR` and `eleanor.lock_dir` to `ELEANOR_LOCK_DIR`. A test that boots the kernel sets both to children of a fresh temporary directory, `data/` and `lock/`, before the kernel boots. `var/` stays gitignored. Neither directory is committed.

## Data flow

`POST /api/archives` reaches the owner's `ArchiveService` as it does today.

1. The service trims the URL and takes the lock.
2. `findSummary` misses, or returns the stored summary.
3. A miss fetches and `save` writes `archive`, `archive_body`, and, on the first write, `meta`.
4. The controller answers 201 or 200 from `summary->url` and `summary->title`.

Two overlapping POSTs of one owner and one URL share one lock. The second finds the row and answers 200 without a fetch. A different URL, or a different owner, has a different key and does not wait.

When the lock is not taken, the same steps run without it. The upsert leaves one row. The worst result is one extra fetch.

A failed fetch stores nothing.

## Errors

A failed `save` throws `ArchiveException` with `ArchiveFailure::NotWritten` (`archive_not_written`) and the `PDOException` as the cause. `ArchiveFailurePresenter` maps it to 500 and the fixed sentence `The archive could not be stored.` The driver text is not in that sentence. `TranslateApiException` already logs the exception, and therefore the cause, on the `application` channel for a status of 500 or above.

A failure of `findSummary` or `find` is not wrapped. `ErrorTranslator` turns that throwable into 500 `unexpected` with `Something went wrong on the server.`

The 400, 415, 405, 422, and 502 answers stay as slice 6 left them.

A lock that is not taken is not an HTTP error.

## Test

A test observes behavior at its seam. It does not assert a SQL string, a pragma read back from a private call, or a lock file name.

`CreateArchiveRouteTest` is the highest seam. It runs on the SQLite store, with the temporary data and lock directories, and the `ArticleFetcher` double. The archive survives without `disableReboot`. Cases:

- 201, then 200, and no second fetch.
- 201 for a different URL.
- A title with a quote and a non-ASCII letter comes back unchanged.
- A second kernel on the same directory still answers 200.
- A failed fetch stores nothing, and the same URL archives on a later POST.
- A data directory that is not writable answers 500 `archive_not_written`, with the fixed sentence and no leaked driver text.

`ArchiveStoreContractTest` runs one set of cases against the in-memory store and the SQLite store: absence, `save` then `find` and `findSummary`, the summary without the HTML, a second `save` of the same URL that updates the title and leaves `find` with that one archive, two stores for one owner sharing rows, and another owner not seeing them. The SQLite cases add: a lookup creates no file, the first `save` creates one, two owners get two files, and the same email in a different letter case names the same file.

`OwnerTest`: `Ada@Example.com` is stored as `ada@example.com`.

`ArchiveServiceTest` uses a fake store and a fake lock. Cases, as revised by [UrlPointer.md](UrlPointer.md): the pointer the service receives is the one the lock and the store see; lookup, fetch, and save run inside the lock; a waiter returns `AlreadyStored` without a fetch; the lock is released when the fetch throws; a lock that cannot be taken still archives. The NUL rules are covered on `UrlPointer` itself.

`FlockArchiveLockTest` uses a temporary directory. Two keys do not block each other. One key blocks a second holder until the first releases. Taking the same key again in one process, after the first release, does not deadlock. The wait limit, passed short through the constructor, lets a second holder proceed. An unwritable lock directory logs a warning and does not throw. The tests do not sleep for 15 seconds.

`ArchiveServiceFactoryTest` builds two services from `SqliteArchiveStoreFactory` on one temporary directory. The service for a second owner gets `Written` for a URL the first owner already stored.

A socket test, a browser test, a real network fetch, and a true two-process race are not seams. `./bin/quality` passing for `web-app` is an acceptance check, not a test.

## Out of scope

Text search, a plain-text column, a full-text index, and any HTML-to-text step. Assets, tags, notes, highlights, and embeddings. Refreshing a changed page, and an `updated_at` column. An idempotency key. A negative cache. A configurable lock wait. `request_terminate_timeout`. A second owner signed in at the same time, URL normalization beyond the NUL trim and the lowercased email, and the firewall and token slices. Upgrading SQLite in the image. A backup or export command. Stripping a NUL from the middle of a URL, and stripping dots or `+` tags from an email.
