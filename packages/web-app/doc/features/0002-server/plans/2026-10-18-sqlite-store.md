# SQLite Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/archives` stores each owner's archives in one SQLite file, answers a repeat from that file without a fetch, and lets two overlapping posts of one URL take one lock.

**Architecture:** `Owner` lowercases the email. `ArchiveStore` gains `findSummary`, and `ArchiveResult` carries an `ArchiveSummary`. The service trims leading and trailing NUL bytes, then runs lookup, fetch, and save inside `ArchiveLock::exclusive`. The production store is PDO SQLite, one file per owner. The production lock is `symfony/lock` `FlockStore` wrapped in `RetryTillSaveStore`. The in-memory store moves to the test suite and shares one contract test with SQLite.

**Tech Stack:** PHP 8.5, Symfony 8.1, PDO `pdo_sqlite`, `symfony/lock` 8.1.*, PHPUnit 11, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/SqliteStore.md`

## Global Constraints

- `Owner` stores `mb_strtolower($email, 'UTF-8')`. Dots and `+` tags stay.
- `trim($url, "\0")` only. A NUL in the middle stays. Whitespace stays.
- Lock key is the owner's email, a NUL byte, and the trimmed URL. The service passes the URL only.
- Wait limit is 15 seconds, constructor argument, not configurable. Timeout runs the action and logs nothing. An I/O failure logs `archive.lock.not_taken` on `application` and runs the action.
- `PRAGMA page_size = 8192` before tables. `foreign_keys = ON`. `busy_timeout = 5000`. `SqliteSchema` then sets `user_version`. `PRAGMA journal_mode = WAL` after the tables.
- `created_at` default is `strftime('%Y-%m-%dT%H:%M:%SZ', 'now')`. Upsert uses `ON CONFLICT(url) DO UPDATE` of `title`, `excerpt`, and `site_name` only. Not `INSERT OR REPLACE`.
- A failed `save` is `ArchiveFailure::NotWritten` (`archive_not_written`), status 500, message `The archive could not be stored.`, cause the `PDOException`. `findSummary` and `find` are not wrapped.
- File name is `hash('sha256', $owner->email) . '.sqlite'` under `eleanor.data_dir`. Locks live under `eleanor.lock_dir`.
- Tests do not assert a SQL string, a pragma, or a lock file name.
- PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Docs and comments in English. `#[Exclude]` on classes that are not services. Commit scope `web-app`, Conventional Commits.

## Decisions

- **Bounded wait.** `new RetryTillSaveStore(new FlockStore($lockDirectory), 50, intdiv($waitMilliseconds, 50))`, then `LockFactory::createLock($key)->acquire(true)`. `false` is the timeout. Any other throwable is the I/O path. `FlockStore` creates the lock directory; a failure there is the I/O path.
- **Same-process flock does not block.** Tests that need a held lock start a child with `proc_open([PHP_BINARY, ...])` and the project autoload. The child constructs `FlockArchiveLock` and holds `exclusive` until a release file exists, or for a fixed short sleep. The parent does not sleep 15 seconds.
- **Missing data directory.** `save` calls `mkdir($dir, 0777, true)`. If that throws, `save` throws `new \PDOException('The data directory could not be created.', 0, $error)` so the service's `PDOException` catch still applies.
- **Kernel directories.** `services_test.yaml` maps the two parameters to `ELEANOR_DATA_DIR` and `ELEANOR_LOCK_DIR`. A test sets both, as children `data/` and `lock/` of a fresh temp directory, before `createClient()` or `bootKernel()`.

## Review Focus

1. A posted `\0https://example.com/a\0` is the same archive and the same lock key as `https://example.com/a`. Task 5 `test_leading_and_trailing_nul_are_trimmed_before_lock_and_store`.
2. A NUL in the middle stays, so `https://example.com/\0a` is not `https://example.com/a`. Task 5 `test_a_nul_in_the_middle_stays_part_of_the_url`.
3. Spaces stay, so ` https://example.com/a` is not `https://example.com/a`. Task 5 `test_surrounding_spaces_stay_part_of_the_url`.
4. `Ada+Box@Example.com` becomes `ada+box@example.com`. The `+` and the dot stay. Task 1 `test_lowercases_the_whole_address_and_keeps_plus_and_dots`.
5. An unwritable data directory answers 500 `archive_not_written` with `The archive could not be stored.` and no driver text. Task 5 `test_unwritable_data_directory_answers_archive_not_written`.

## File map

- Modify: `src/Archive/Owner.php`, `ArchiveStore.php`, `ArchiveResult.php`, `ArchiveService.php`, `ArchiveServiceFactory.php`, `ArchiveFailure.php`, `Api/Controller/CreateArchiveController.php`, `Api/Error/ArchiveFailurePresenter.php`.
- Create: `src/Archive/ArchiveSummary.php`, `ArchiveLock.php`, `ArchiveLockFactory.php`, `Sqlite/{SqliteConnection,SqliteSchema,SqliteArchiveStore,SqliteArchiveStoreFactory}.php`, `Lock/{FlockArchiveLock,FlockArchiveLockFactory}.php`.
- Move: `src/Archive/InMemory/*` to `tests/Support/InMemory/*` (`Yumo\Eleanor\Tests\Support\InMemory`).
- Create tests: `tests/Support/{FakeArchiveStore,FakeArchiveLock,ArchiveDirectories}.php`, `tests/Archive/{ArchiveStoreContractTest,Lock/FlockArchiveLockTest}.php`.
- Modify tests: `OwnerTest`, `ArchiveServiceTest`, `ArchiveServiceFactoryTest`, `Api/Resolver/ArchiveServiceResolverTest`, `Integration/{CreateArchiveRouteTest,ErrorResponseRouteTest,LogApiRequestTest}`, `Api/OpenApiDocumentTest` only if a code list is hardcoded (it is derived from the enum; regenerating `doc/openapi.yaml` is what must change).
- Modify: `composer.json`, `composer.lock`, `config/services.yaml`, `config/services_test.yaml`, `doc/openapi.yaml`, `doc/features/0002-server/feature.md`.

---

### Task 1: Owner lowercases the email

**Files:**
- Modify: `packages/web-app/src/Archive/Owner.php`
- Test: `packages/web-app/tests/Archive/OwnerTest.php`
- Modify: `packages/web-app/doc/features/0002-server/feature.md` slice 7 row, status `In progress`

**Interfaces:**
- Produces: `Owner::__construct(string $email)` stores `mb_strtolower($email, 'UTF-8')` on `public string $email`.

- [ ] **Step 1: Write the failing tests.** Replace `test_keeps_the_email`. `test_lowercases_the_whole_address_and_keeps_plus_and_dots`: `new Owner('Ada+Box@Example.com')` has email `ada+box@example.com`. `test_keeps_an_ascii_address`: `ada@example.com` stays `ada@example.com`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter OwnerTest`.** Expected: FAIL, email is `Ada+Box@Example.com`.

- [ ] **Step 3: Implement.** Assign the lowercased value in the constructor. Set the slice 7 row in `feature.md` to `In progress`.

- [ ] **Step 4: Run `./bin/test web-app -- --filter OwnerTest`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): lowercase the owner email`.

### Task 2: Summary read

**Files:**
- Create: `packages/web-app/src/Archive/ArchiveSummary.php`
- Modify: `packages/web-app/src/Archive/ArchiveResult.php`, `ArchiveStore.php`, `ArchiveService.php`, `src/Archive/InMemory/InMemoryArchiveShelf.php`, `InMemoryArchiveStore.php`, `Api/Controller/CreateArchiveController.php`
- Test: `packages/web-app/tests/Archive/InMemory/InMemoryArchiveStoreTest.php`, `tests/Archive/ArchiveServiceTest.php`

**Interfaces:**
- Consumes: `Owner` from Task 1.
- Produces: `final readonly class ArchiveSummary` with `string $url`, `string $title`, `?string $excerpt`, `?string $siteName`. `#[Exclude]`.
- Produces: `ArchiveResult` properties `ArchiveSummary $summary`, `ArchiveOutcome $outcome`.
- Produces: `ArchiveStore::findSummary(string $url): ?ArchiveSummary`. `find` and `save` stay.
- Produces: `ArchiveService::archive` calls `findSummary`. A hit returns `AlreadyStored` with that summary and does not fetch. A miss fetches, saves, and returns `Written` whose summary is built from the saved `Archive` (`url`, `title`, `excerpt`, `siteName`). No lock and no NUL trim in this task.

- [ ] **Step 1: Write the failing test.** `InMemoryArchiveStoreTest::test_find_summary_returns_the_light_fields`: after `save` of an archive with html `<p>x</p>`, `findSummary` equals `new ArchiveSummary($url, $title, $excerpt, $siteName)` and `find($url)->html` is `<p>x</p>`. Update `ArchiveServiceTest` so assertions that need html use `$this->store->find(...)`, and assertions of the result use `$result->summary`. `test_second_archive_is_already_stored_and_does_not_fetch` compares `$first->summary` with `$second->summary`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'InMemoryArchiveStoreTest|ArchiveServiceTest'`.** Expected: FAIL, `findSummary` or `summary` is unknown.

- [ ] **Step 3: Implement** the types, the shelf and store methods, the service branch, and the controller read of `$result->summary->url` and `->title`. In-memory `save` still replaces the row for that owner and URL.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): return an archive summary without the html`.

### Task 3: Flock lock

**Files:**
- Create: `packages/web-app/src/Archive/ArchiveLock.php`, `ArchiveLockFactory.php`, `src/Archive/Lock/FlockArchiveLock.php`, `FlockArchiveLockFactory.php`
- Test: `packages/web-app/tests/Archive/Lock/FlockArchiveLockTest.php`
- Modify: `packages/web-app/composer.json`, `composer.lock`, `config/services.yaml`

**Interfaces:**
- Consumes: `Owner` from Task 1.
- Produces: `interface ArchiveLock { public function exclusive(string $url, callable $action): mixed; }`. The callable takes no arguments. Release on return and on throw. If the lock is not taken, the callable still runs.
- Produces: `interface ArchiveLockFactory { public function create(Owner $owner): ArchiveLock; }`.
- Produces: `FlockArchiveLock::__construct(Owner $owner, string $lockDirectory, LoggerInterface $logger, int $waitMilliseconds = 15_000)`. Key is `$owner->email . "\0" . $url`. `#[Exclude]`.
- Produces: `FlockArchiveLockFactory::__construct(string $lockDirectory, LoggerInterface $logger)` with `create(Owner): FlockArchiveLock` at the default wait. Not `#[Exclude]`.

- [ ] **Step 1: Write the failing tests** in `FlockArchiveLockTest`, each with a temp directory and a logger double that records warnings.

`test_second_acquire_after_release_does_not_deadlock`: two `exclusive` calls on one key in this process both return their values.

`test_different_keys_do_not_block`: a child holds `https://example.com/a` until a release file appears. The parent `exclusive('https://example.com/b', ...)` with wait `2000` returns before the parent writes that release file.

`test_one_key_blocks_until_the_first_releases`: a child holds one key for 300 ms. The parent `exclusive` on that key with wait `5000` returns, and the elapsed time is at least 200 ms.

`test_wait_limit_runs_the_action_without_a_warning`: a child holds one key and does not release. The parent uses wait `0`. The action return value comes back. The logger has no warning. The parent then kills the child.

`test_unwritable_lock_directory_logs_and_runs`: the lock directory mode is `0555`. `exclusive` returns the action value and does not throw. The logger has one warning whose message is `archive.lock.not_taken`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter FlockArchiveLockTest`.** Expected: FAIL, class not found.

- [ ] **Step 3: Run `./bin/composer web-app require symfony/lock:8.1.*`.** Implement the interfaces and `FlockArchiveLock` with `RetryTillSaveStore` as in Decisions. Catch `\Throwable` around `acquire(true)`, log, and run the action. A `false` acquire runs the action with no log. `release()` is in a `finally` only when the acquire returned `true`. In `services.yaml`, set `eleanor.lock_dir: '%kernel.project_dir%/var/lock'` and bind `FlockArchiveLockFactory` `$lockDirectory` to that parameter. Put `#[WithMonologChannel('application')]` on the factory so the logger is the `application` channel. Nothing injects the factory yet. The binding exists so the kernel still compiles.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): add a per-url flock lock`.

### Task 4: SQLite store

**Files:**
- Create: `packages/web-app/src/Archive/Sqlite/SqliteConnection.php`, `SqliteSchema.php`, `SqliteArchiveStore.php`, `SqliteArchiveStoreFactory.php`
- Move: `packages/web-app/src/Archive/InMemory/` to `packages/web-app/tests/Support/InMemory/`
- Create: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`, `packages/web-app/tests/Support/ArchiveDirectories.php`
- Modify: `packages/web-app/composer.json` (`ext-pdo_sqlite`), `config/services.yaml`, `config/services_test.yaml`, every test that imports `Yumo\Eleanor\Archive\InMemory`, `tests/Integration/CreateArchiveRouteTest.php`, `ErrorResponseRouteTest.php`, `LogApiRequestTest.php`, `tests/Archive/ArchiveServiceFactoryTest.php`
- Delete: `packages/web-app/src/Archive/InMemory/`

**Interfaces:**
- Consumes: `ArchiveStore` and `ArchiveSummary` from Task 2. `Owner` from Task 1.
- Produces: `SqliteConnection::open(string $path): \PDO` and `close(): void`. Order inside `open`: `page_size = 8192`, `foreign_keys = ON`, `busy_timeout = 5000`, `$schema->migrate($pdo)`, `journal_mode = WAL`.
- Produces: `SqliteSchema::migrate(\PDO $pdo): void`. Version 0 runs the SQL below and `PRAGMA user_version = 1`. Version 1 returns.
- Produces: `SqliteArchiveStore::__construct(Owner $owner, string $path)`, `#[Exclude]`. `findSummary` and `find` return null when the file does not exist. `save` creates the parent directory, opens, writes, closes.
- Produces: `SqliteArchiveStoreFactory::__construct(string $dataDirectory): ArchiveStoreFactory`. Path is `$dataDirectory . '/' . hash('sha256', $owner->email) . '.sqlite'`.

Version 1 SQL, as the body of `migrate` when `user_version` is 0:

```sql
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
```

`save` transaction: insert `meta` keys `email` and `created_at` when `email` is absent (`created_at` uses the same `strftime`). Then:

```sql
INSERT INTO archive (url, title, excerpt, site_name)
VALUES (:url, :title, :excerpt, :site_name)
ON CONFLICT(url) DO UPDATE SET
    title = excluded.title,
    excerpt = excluded.excerpt,
    site_name = excluded.site_name
RETURNING id;
```

```sql
INSERT INTO archive_body (archive_id, html)
VALUES (:id, :html)
ON CONFLICT(archive_id) DO UPDATE SET html = excluded.html;
```

- [ ] **Step 1: Write the failing contract test.** `ArchiveStoreContractTest` builds both stores from one provider. Shared cases: unknown URL is null from `find` and `findSummary`; `save` then `find` equals the archive and `findSummary` equals the light summary; a second `save` of the same URL with another title leaves `find` equal to that one archive and `find` of a second URL null; two stores for one owner share a row; another owner does not. SQLite-only: `findSummary` creates no file; the first `save` creates one `*.sqlite`; `Ada@Example.com` and `ada@example.com` leave one file; two owners leave two files; a missing parent directory is created by `save`.

`ArchiveDirectories::isolate(): string` creates the temp root, `mkdir` of `data/` and `lock/`, and sets `ELEANOR_DATA_DIR` and `ELEANOR_LOCK_DIR` in `putenv` and `$_SERVER`. Call it at the start of `setUp` in `CreateArchiveRouteTest`, `ErrorResponseRouteTest`, and `LogApiRequestTest`, before the kernel boots. Remove `disableReboot()` from `test_same_url_again_returns_200_with_the_same_body_and_one_fetch`. Add `test_second_kernel_on_the_same_directory_returns_200_without_a_fetch`: shut the kernel down, boot again without calling `isolate` a second time, POST the same URL, assert 200 and `fetchCount` 0. Add `ArchiveServiceFactoryTest::test_second_owner_gets_written_for_a_url_the_first_owner_stored` using `SqliteArchiveStoreFactory` and one temp directory: first owner `Written`, second owner `Written`, two fetches.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'ArchiveStoreContractTest|ArchiveServiceFactoryTest|CreateArchiveRouteTest'`.** Expected: FAIL, sqlite classes or the new test methods are missing.

- [ ] **Step 3: Implement.** Add `ext-pdo_sqlite` to `composer.json`. Move the in-memory classes and switch their namespace; update imports. `services.yaml`: `eleanor.data_dir: '%kernel.project_dir%/var/data'`. `services_test.yaml`: `eleanor.data_dir: '%env(ELEANOR_DATA_DIR)%'`. `SqliteArchiveStoreFactory` is the only `ArchiveStoreFactory` in `src/`, argument `$dataDirectory: '%eleanor.data_dir%'`. Do not change `ArchiveServiceFactory`'s constructor in this task. The second-owner test constructs `SqliteArchiveStoreFactory` itself and the existing same-owner test keeps using the moved in-memory factory. The lock binding from Task 3 stays.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): store each owner's archives in sqlite`.

### Task 5: Service lock, write failure, and the route

**Files:**
- Create: `packages/web-app/tests/Support/FakeArchiveStore.php`, `FakeArchiveLock.php`
- Modify: `packages/web-app/src/Archive/ArchiveService.php`, `ArchiveServiceFactory.php`, `ArchiveFailure.php`, `Api/Error/ArchiveFailurePresenter.php`, `Api/Controller/CreateArchiveController.php` (OpenAPI 500 enum), `config/services.yaml`, `config/services_test.yaml`, `doc/openapi.yaml`
- Test: `packages/web-app/tests/Archive/ArchiveServiceTest.php`, `tests/Integration/CreateArchiveRouteTest.php`, `tests/Api/Error/ErrorTranslatorTest.php` if a case list is exhaustive
- Modify: `packages/web-app/doc/features/0002-server/feature.md`

**Interfaces:**
- Consumes: `ArchiveLock` and `FlockArchiveLockFactory` from Task 3. `SqliteArchiveStoreFactory` from Task 4. `ArchiveSummary` from Task 2.
- Produces: `ArchiveService::__construct(ArchiveStore $store, ArticleFetcher $fetcher, ArchiveLock $lock, ArchiveFailureMapper $failures = new ArchiveFailureMapper())`.
- Produces: `archive` does `trim($url, "\0")`, then `$this->lock->exclusive($url, ...)`. Inside: `findSummary`, fetch, `save`. `catch (\PDOException $error)` around `save` throws `new ArchiveException(ArchiveFailure::NotWritten, $error)`.
- Produces: `ArchiveFailure::NotWritten = 'archive_not_written'`. Presenter status `500`, message `The archive could not be stored.`
- Produces: `ArchiveServiceFactory::__construct(ArticleFetcher $fetcher, ArchiveStoreFactory $stores, ArchiveLockFactory $locks)`.

- [ ] **Step 1: Write the failing tests.** `FakeArchiveStore` records `find`, `save`, and the URL, and can throw from `save`. `FakeArchiveLock` appends `enter` and `leave` around the callable and records the URL. `ArchiveServiceTest` uses those fakes instead of the in-memory store. Existing behavior stays, with `summary` and `store->find` as in Task 2.

Add:

`test_leading_and_trailing_nul_are_trimmed_before_lock_and_store`: `archive("\0https://example.com/a\0")` stores and locks `https://example.com/a`.

`test_a_nul_in_the_middle_stays_part_of_the_url`: stored URL is `https://example.com/\0a`, and `findSummary('https://example.com/a')` is null.

`test_surrounding_spaces_stay_part_of_the_url`: stored URL is ` https://example.com/a`.

`test_lookup_fetch_and_save_run_inside_the_lock`: the shared log is `['enter', 'find', 'fetch', 'save', 'leave']`.

`test_waiter_returns_already_stored_without_a_fetch`: store already has the summary, fetch count stays 0, outcome is `AlreadyStored`.

`test_lock_is_released_when_the_fetch_throws`: the fetcher throws `OrchestratorException`, the log ends with `leave`, and `save` was not called.

`test_a_lock_that_is_not_taken_still_archives`: the fake runs the action and records that it did not hold the lock; outcome is `Written`.

`test_a_pdo_exception_from_save_is_not_written`: `save` throws `new \PDOException('disk full near /var/secret')`. The thrown `ArchiveException` has `failure === NotWritten` and `getPrevious()` that same exception.

`test_a_pdo_exception_from_find_summary_is_not_wrapped`: `findSummary` throws `\PDOException`. The test expects `\PDOException`.

Add a `yield 'archive_not_written'` to the provider of `ErrorTranslatorTest::test_translates_the_throwable`: `new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('disk full'))`, status 500, code `archive_not_written`, message `The archive could not be stored.` Assert the body of that case does not contain `disk full`.

`CreateArchiveRouteTest::test_unwritable_data_directory_answers_archive_not_written`: after `isolate()`, `chmod` the data directory to `0555`, POST a new URL whose fetch succeeds, assert status 500, JSON `{"code":"archive_not_written","message":"The archive could not be stored."}`, and that the body does not contain `PDO` or `SQLSTATE`. Restore the mode in `finally`.

Quote, backslash, and non-ASCII titles already have route tests. `ErrorResponseRouteTest::test_a_failed_post_stores_nothing_and_a_retry_fetches_again` already proves a failed fetch stores nothing. Leave its `disableReboot()` in place so the scripted failure survives on the fake.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'ArchiveServiceTest|ErrorTranslatorTest|CreateArchiveRouteTest'`.** Expected: FAIL, constructor arguments or `NotWritten` missing.

- [ ] **Step 3: Implement** the service, the factory, the enum case, both presenter `match` arms, and the OpenAPI 500 enum entry `ArchiveFailure::NotWritten->value`. `services_test.yaml` maps `eleanor.lock_dir` to `'%env(ELEANOR_LOCK_DIR)%'`. `ArchiveDirectories::isolate` already sets that env. Update `ArchiveServiceFactoryTest` and `ArchiveServiceResolverTest` to pass an `ArchiveLockFactory`. A test-only factory that returns `FakeArchiveLock` is enough there, except the second-owner case, which uses `FlockArchiveLockFactory` on the temp lock directory so the production factory is what the test builds. The same-owner factory test can keep the in-memory store and a fake lock. Run `./bin/composer web-app openapi` and commit the regenerated `doc/openapi.yaml`. In `feature.md`, set slice 7 to `Done`, link this plan and `specs/SqliteStore.md`, and delete the leftover row about the second owner.

- [ ] **Step 4: Run `./bin/test web-app` and `./bin/quality`.** Expected: both exit 0.

- [ ] **Step 5: Commit** `feat(web-app): take the archive lock around one url`.
