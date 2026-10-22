# Archive log outcomes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `ArchiveService` logs the branch it took, and does not log the lookup or the fetch it is about to run.

**Architecture:** Three private methods own the three lines: `alreadyStored`, `written`, and `failed`. `failed` is the only writer of `archive.failed` and adds `store` when the failure is `NotWritten`. `log` and `context` stay. No new type.

**Tech Stack:** PHP 8.5, PSR-3 `LoggerInterface`, PHPUnit.

**Spec:** [ArchiveLog.md](../design/ArchiveLog.md)

## Global Constraints

- The message is the event name. Values sit in `context`.
- No article HTML, title, email, bearer token, or exception on these lines.
- A failed log write is caught and does not change the archive outcome.
- A throwable that is not `ArchiveException` writes no domain line and passes through.
- `store` is present on `archive.written`, and on `archive.failed` only when `code` is `archive_not_written`, and only when `location()` is a string.

## Review Focus

- A lookup throw used to leave `archive.start` so the URL was on `domain`. After this change that URL is absent. The test must assert no records, so a later edit cannot put the start line back without a spec change.
- `archive.failed` for a fetch must not gain `store` just because `location()` is a string.

### Task 1: Outcomes only

**Files:**
- Modify: `packages/web-app/tests/Archive/ArchiveServiceTest.php`
- Modify: `packages/web-app/src/Archive/ArchiveService.php`
- Modify: `packages/web-app/tests/Request/RequestIdProcessorTest.php` (sample message only)

**Interfaces:**
- Consumes: `ArchiveService::archive(UrlPointer): ArchiveResult`, `LoggerInterface`
- Produces: `alreadyStored(UrlPointer): void`, `written(UrlPointer): void`, `failed(UrlPointer, ArchiveException): void`. `failed` calls `log('warning', 'archive.failed', context)` and passes `store: true` only when `$failure->failure === ArchiveFailure::NotWritten`.

- [x] **Step 1: Write the failing test**

A hit's records are `archive.already_stored` only. A miss's records are `archive.written` only. A mapped fetch failure's records are `archive.failed` with `code` and without `store`. A `NotWritten` save failure's records are that one `archive.failed` line, with `store` when `location()` is a string. A `PDOException` or `DirectoryException` from `save`, and a `PDOException` from `findSummary`, leave `records` empty.

- [x] **Step 2: Run the test and confirm it fails**

Run: `./bin/test web-app -- --filter ArchiveServiceTest`
Expected: FAIL because the service still writes `archive.start` and `archive.fetch`.

- [x] **Step 3: Implement**

Delete the `archive.start` and `archive.fetch` calls. Call `alreadyStored`, `written`, and `failed` from the branches. `save` calls `failed` and rethrows the same `ArchiveException`.

- [x] **Step 4: Run the test and `./bin/quality`**

Expected: the filter passes, then `./bin/quality` exits 0.
