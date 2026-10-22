# Store Save Failure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A failed `save` throws `ArchiveException` with `NotWritten` from `SqliteArchiveStore`, and `ArchiveService` logs that exception and rethrows it.

**Architecture:** `SqliteArchiveStore::save` catches `DirectoryException` and `\PDOException` around its existing `withConnection` call and throws `ArchiveException` with that exception as the cause. The transaction rollback inside the callback stays. `withConnection` does not catch, so `findSummary` and `find` still let those two through. `SqliteConnection` still raises them. `ArchiveService` catches `ArchiveException` from `save`, writes `archive.failed`, and rethrows the same instance.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 11, PDO SQLite, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/SqliteStore.md`, `packages/web-app/doc/features/0002-server/specs/ArchiveCommand.md`, and `packages/web-app/doc/features/0002-server/specs/ArchiveLog.md`. The index note is [Store save failure](../feature.md#store-save-failure-2026-10-22).

## Global Constraints

- `ArchiveStore::save` throws `ArchiveException` with `NotWritten` when the archive cannot be stored. `findSummary` and `find` do not wrap a failure.
- `SqliteArchiveStore::save` turns `DirectoryException` and `\PDOException` into that `ArchiveException`. The cause is the original exception. `SqliteConnection::open` still raises `DirectoryException` for a directory and `\PDOException` for a database failure.
- The shared `withConnection` helper does not catch. The callback in `save` still rolls back an open transaction and rethrows before the wrap.
- `ArchiveService` logs `archive.failed` with the failure code on that exception and rethrows the same instance. It does not catch `DirectoryException` or `\PDOException`. Any other throwable from `save` passes through. `archive.failed` for a save includes `store` when `location()` is a string and omits it when `location()` is null.
- A lookup that throws writes `archive.start` only. It does not write `archive.failed`.
- The client sentence stays `The archive could not be stored.` Status stays 500. Code stays `archive_not_written`. The driver text is not in that sentence.
- No new glossary term. No ADR. ADR 0008 stays closed. Do not edit the current-ticket row in `feature.md`. Docs and comments in English. PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.

## Review Focus

1. A file standing where the data directory should be fails `save` with `NotWritten`, cause `DirectoryException`, and that cause has no previous. No database file appears. Task 1 `test_save_fails_without_a_file_when_the_directory_cannot_be_created`.
2. A non-database file standing at the SQLite path fails `save` with `NotWritten` and a `\PDOException` cause. Task 1 `test_save_of_a_non_database_file_is_not_written`.
3. `findSummary` and `find` on that same file throw `\PDOException`. Task 1 `test_lookup_of_a_non_database_file_is_not_wrapped`.
4. The service rethrows the store's `ArchiveException` instance and does not build another one. A `\PDOException` or a `DirectoryException` from `save` passes through and writes no `archive.failed`. Task 2 `test_an_archive_exception_from_save_is_rethrown`, `test_a_pdo_exception_from_save_passes_through`, and `test_a_directory_exception_from_save_passes_through`.
5. A logger that throws while logging a failed save still lets that same `NotWritten` escape, with its `\PDOException` cause. Task 2 `test_a_throwing_logger_on_save_failure_still_throws_not_written`.

---

### Task 1: The SQLite store owns a failed save

**Files:**
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/src/Archive/ArchiveStore.php`
- Test: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`

**Interfaces:**
- Consumes: `ArchiveException`, `ArchiveFailure::NotWritten`, `DirectoryException`, `\PDOException`.
- Produces: `SqliteArchiveStore::save(Archive $archive): void` throws `ArchiveException` with `NotWritten` and the sqlite exception as `getPrevious()`. `findSummary` and `find` are unchanged. `withConnection` does not catch.

- [x] **Step 1: Write the failing tests**

In `test_save_fails_without_a_file_when_the_directory_cannot_be_created`, `save` throws `ArchiveException`. `failure` is `ArchiveFailure::NotWritten`. `getPrevious()` is a `DirectoryException` whose own `getPrevious()` is null. The database file does not exist.

Add `test_save_of_a_non_database_file_is_not_written`. Write the bytes `not a database` at `$this->directory . '/ada.sqlite'`, then `save` an archive through `sqliteStore($path)`. The exception is `ArchiveException` with `NotWritten`, and `getPrevious()` is a `\PDOException`.

Add `test_lookup_of_a_non_database_file_is_not_wrapped` on that same file. `findSummary` throws `\PDOException`. `find` throws `\PDOException`. Neither throw is an `ArchiveException`.

- [x] **Step 2: Run `./bin/test web-app -- --filter 'test_save_fails_without_a_file_when_the_directory_cannot_be_created|test_save_of_a_non_database_file_is_not_written|test_lookup_of_a_non_database_file_is_not_wrapped'`.** Expected: FAIL on the two `save` tests, because `save` still throws `DirectoryException` or `\PDOException`. The lookup test may already pass.

- [x] **Step 3: Wrap `save` only**

Catch `DirectoryException` and `\PDOException` around the existing `withConnection` call in `save`. Throw `new ArchiveException(ArchiveFailure::NotWritten, $error)`. Leave the transaction `catch (\Throwable)` inside the callback so it still rolls back and rethrows. Do not catch in `withConnection`.

```php
public function save(Archive $archive): void
{
    try {
        $this->withConnection(function (\PDO $pdo) use ($archive): void {
            // existing body, including the rollback catch
        });
    } catch (DirectoryException $error) {
        throw new ArchiveException(ArchiveFailure::NotWritten, $error);
    } catch (\PDOException $error) {
        throw new ArchiveException(ArchiveFailure::NotWritten, $error);
    }
}
```

On `ArchiveStore::save`, document `@throws ArchiveException` with `NotWritten`. On `findSummary` and `find`, document that a failure is not wrapped. On `SqliteArchiveStore`, say `save` throws `ArchiveException` and the connection still raises `DirectoryException` and `\PDOException`.

- [x] **Step 4: Run `./bin/test web-app -- --filter 'ArchiveStoreContractTest|SqliteConnectionTest|test_unwritable_data_directory_answers_archive_not_written'`.** Expected: PASS. `SqliteConnectionTest` still sees a raw `DirectoryException`. The route body is still `{"code":"archive_not_written","message":"The archive could not be stored."}` and contains neither `PDO` nor `SQLSTATE`.

- [x] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php packages/web-app/src/Archive/ArchiveStore.php packages/web-app/tests/Archive/ArchiveStoreContractTest.php packages/web-app/doc/features/0002-server/plans/2026-10-22-store-save-failure.md
git commit -m "$(cat <<'EOF'
refactor(web-app): keep a failed save behind the store

EOF
)"
```

### Task 2: The command logs the store's exception

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveService.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceTest.php`
- Modify: `packages/web-app/doc/features/0002-server/specs/ArchiveLog.md`
- Modify: `packages/web-app/doc/features/0002-server/feature.md` (the Store save failure section only)

**Interfaces:**
- Consumes: `ArchiveStore::save` throws `ArchiveException` with `NotWritten`. `context(UrlPointer $url, ?string $code = null, bool $store = false): array`.
- Produces: `ArchiveService` catches `ArchiveException` from `save`, logs `archive.failed` with `$error->failure->value` and `store: true`, and rethrows that same instance. `notWritten` is deleted. The service file does not import `DirectoryException`.

- [ ] **Step 1: Rewrite the failing service tests**

Delete `test_a_pdo_exception_from_save_is_not_written`, `test_a_directory_exception_from_save_is_not_written`, `test_a_pdo_exception_from_save_logs_failed_with_store`, and `test_a_directory_exception_from_save_logs_failed_with_store`.

Add `test_an_archive_exception_from_save_is_rethrown`. `saveFailure` is `$failure = new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('locked'))`. `location` is `/data/ada.sqlite`. The thrown exception is `assertSame($failure, $exception)`. The third log record is `['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written', 'store' => '/data/ada.sqlite']]`.

Add `test_a_pdo_exception_from_save_passes_through` and `test_a_directory_exception_from_save_passes_through`. `saveFailure` is `new \PDOException('locked')` or `new DirectoryException('The data directory could not be created.')`. The thrown exception is that type. The records are `archive.start` and `archive.fetch` only.

Rewrite `test_a_save_failure_without_a_location_omits_store` so `saveFailure` is `new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('locked'))` and `location` stays null. The failed record has no `store` key.

Rewrite `test_a_throwing_logger_on_save_failure_still_throws_not_written` so `saveFailure` is that same `ArchiveException`. The thrown exception is the same instance, and `getPrevious()` is a `\PDOException`.

Leave `test_a_pdo_exception_from_find_summary_is_not_wrapped`, `test_a_directory_exception_from_find_summary_is_not_wrapped`, and `test_a_pdo_exception_from_find_summary_logs_start_only` as they are.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'test_an_archive_exception_from_save_is_rethrown|test_a_pdo_exception_from_save_passes_through|test_a_directory_exception_from_save_passes_through|test_a_save_failure_without_a_location_omits_store|test_a_throwing_logger_on_save_failure_still_throws_not_written'`.** Expected: FAIL. The service still turns a `\PDOException` from `save` into a new `ArchiveException`, and it does not rethrow the fake's `ArchiveException` after logging it.

- [ ] **Step 3: Rethrow the store's exception**

Replace the two sqlite catches and delete `notWritten`. Remove the `DirectoryException` import from `ArchiveService.php`. The test file keeps that import.

```php
private function save(UrlPointer $url, Archive $archive): void
{
    try {
        $this->store->save($archive);
    } catch (ArchiveException $error) {
        $this->log('warning', 'archive.failed', $this->context($url, $error->failure->value, true));

        throw $error;
    }
}
```

The method doc says the store throws `ArchiveException` and this method logs and rethrows it.

In `feature.md`, under Store save failure, link this plan and delete the sentence that says the code still has the catch in the service. Do not change the current-ticket row. Do not edit the bearer rows in that file. `ArchiveLog.md` already states this log. Leave it as the spec says.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS.

- [ ] **Step 5: Run `./bin/quality`.** Expected: exit 0.

- [ ] **Step 6: Commit**

`feature.md` also holds uncommitted bearer-slice edits. Do not `git add` that file. The Store save failure paragraph stays in the working tree until the bearer edits are committed on their own.

```bash
git add packages/web-app/src/Archive/ArchiveService.php packages/web-app/tests/Archive/ArchiveServiceTest.php packages/web-app/doc/features/0002-server/specs/ArchiveLog.md
git commit -m "$(cat <<'EOF'
refactor(web-app): log a save failure without catching sqlite

EOF
)"
```
