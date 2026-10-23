**Status:** plan written, waiting for review

## Problem Statement

`POST /api/archives` stores each archive in the memory of one PHP process. A second POST in a new process, or after a restart, fetches the page again, and the archive is lost. The owner's list of archives is not a file the owner controls, copies, or backs up. Two requests for the same URL that overlap can both fetch the page and both answer 201.

## Solution

The same route keeps each owner's archives in one SQLite file on the local disk. A POST of a URL the owner already has answers 200 from that file, in any process, and does not fetch. A first POST fetches, writes the file, and answers 201. Two overlapping POSTs of one URL for one owner take turns: the second waits for the first, then finds the stored archive and answers 200 without a fetch. A failed write answers 500 `archive_not_written`. The file holds the URL, the title, the excerpt, the site name, and the purified HTML, and it leaves room for search and other content work later without a change to the archive rows.

## User Stories

1. As a client of Eleanor, I want a POST of a new URL to answer 201 with `url` and `title`, so that a new archive is a write.
2. As a client of Eleanor, I want a second POST of the same URL, even after a restart of the server, to answer 200 with the same `url` and `title`, so that the archive survives the process.
3. As a client of Eleanor, I want that second POST not to fetch the page, so that a repeat does not refresh the page.
4. As a client of Eleanor, I want a POST of a different URL to answer 201, so that each URL has its own archive.
5. As a client of Eleanor, I want `https://example.com/a` and `https://example.com/a/` to be two archives, so that the stored name is the string I sent.
6. As a client of Eleanor, I want a title with a quote, a backslash, or non-ASCII letters to come back unchanged from the stored row, so that storing does not alter the text.
7. As a client of Eleanor, I want an empty title to come back as `""`, so that `title` is present after a restart as before it.
8. As a client of Eleanor, I want two overlapping POSTs of one new URL to answer one 201 and one 200, so that the second does not write a second copy.
9. As a client of Eleanor, I want the overlapping second POST not to fetch the page, so that one page is not downloaded twice.
10. As a client of Eleanor, I want two overlapping POSTs of different URLs not to wait for each other, so that unrelated archives are not serialized.
11. As a client of Eleanor, I want a fetch that fails to store nothing, so that the same URL archives on a later POST.
12. As a client of Eleanor, I want a write that fails after a good fetch to answer 500 with the code `archive_not_written`, so that I know the page was valid and a retry is meaningful.
13. As a client of Eleanor, I want that 500 to carry one fixed sentence and nothing from the inside, so that the cause stays in the log.
14. As a client of Eleanor, I want every other storage failure to answer 500 `unexpected`, so that the error shape from the error-response slice stays.
15. As a client of Eleanor, I want the 400, 415, 405, 422, and 502 answers of the earlier slices to stay as they are, so that this slice changes only what the store changes.
16. As an owner, I want my archives in one SQLite file, so that a copy of that file is my list.
17. As an owner, I want another owner's archives never to appear in my file, so that two lists stay two lists.
18. As an owner, I want `Ada@Example.com` and `ada@example.com` to name the same owner, so that letter case does not split my list.
19. As an owner, I want my file to carry my email inside it, so that a file can be identified without its name.
20. As an owner, I want a lookup of an archive I do not have to create no file, so that an owner who never archives has no file.
21. As an owner, I want the file to be created at my first write, so that nothing needs to be set up by hand.
22. As an operator of Eleanor, I want one parameter to name the directory of the owner files, so that I can point the data at a disk I back up.
23. As an operator of Eleanor, I want the default directory to work with no setup, so that the temporary server runs after a checkout.
24. As an operator of Eleanor, I want the lock files kept outside the data directory, so that a backup of the data directory holds only databases.
25. As an operator of Eleanor, I want a lock that cannot be taken, because of a full disk or a read-only directory, to log a warning and let the archive proceed, so that a broken lock does not stop archiving.
26. As an operator of Eleanor, I want a request that waits too long for a lock to proceed without it, so that a hung holder cannot hold every request for that URL for ever.
27. As an operator of Eleanor, I want a lock to be released when its request ends or its process dies, so that a crash leaves nothing stuck.
28. As an operator of Eleanor, I want the cause of a 500 from the store in the `application` log, so that I can see what the client cannot.
29. As a developer of Eleanor, I want `ArchiveService` to ask the store a light question first, so that the second POST reads no HTML.
30. As a developer of Eleanor, I want that question to return an `ArchiveSummary` of `url`, `title`, `excerpt`, and `siteName`, so that the response and a later list need no body.
31. As a developer of Eleanor, I want `ArchiveStore::find` to stay and return the full `Archive`, so that a later endpoint can return the whole article.
32. As a developer of Eleanor, I want `ArchiveResult` to carry an `ArchiveSummary`, so that `Written` and `AlreadyStored` have the same shape.
33. As a developer of Eleanor, I want `save` to be an upsert, so that a caller that already holds the lock can write without a duplicate error.
34. As a developer of Eleanor, I want `save` to write `archive` and `archive_body` in one transaction, so that a row never exists without its body.
35. As a developer of Eleanor, I want an upsert to keep the row id, so that nothing that points at the archive breaks on a refresh.
36. As a developer of Eleanor, I want `ArchiveService` to take an `ArchiveLock` bound to the owner, so that the service owns the rule and does not know the lock mechanism.
37. As a developer of Eleanor, I want the lock to wrap the lookup, the fetch, and the save of one URL, so that a waiting request finds the archive the first one wrote.
38. As a developer of Eleanor, I want the lock keyed by owner and URL, so that other URLs and other owners never wait.
39. As a developer of Eleanor, I want the lock to be a port with one implementation on local files, so that a second implementation can replace it.
40. As a developer of Eleanor, I want a unit test of the lock to prove that taking the same key twice in one process does not deadlock the test, so that a mistake in the service shows up at once.
41. As a developer of Eleanor, I want the schema version in the file, so that a later migration knows what it starts from.
42. As a developer of Eleanor, I want the first version to hold `meta`, `archive`, and `archive_body`, so that search, assets, tags, and embeddings are later additive tables.
43. As a developer of Eleanor, I want `created_at` set by the database, so that the domain `Archive` does not carry it.
44. As a developer of Eleanor, I want one store contract test class run against the in-memory store and the SQLite store, so that the fake cannot drift from the real adapter.
45. As a developer of Eleanor, I want the kernel tests to run on the SQLite store in a temporary directory, so that the route is proved end to end, including a second kernel.
46. As a developer of Eleanor, I want `ArchiveServiceFactoryTest` to prove that a second owner gets `Written` for a URL the first owner already stored, so that the leftover from slice 5 is closed.
47. Withdrawn 2026-10-18. This story asked for `archive_not_written` in the OpenAPI document. The document is removed. The kernel test covers that code.
48. As a developer of Eleanor, I want `./bin/quality` to pass, so that the slice meets the same bar as the earlier ones.

## Implementation Decisions

- This contract follows the grill of 2026-10-18 and revises slice 5 where it says so. [Storage.md](../research/Storage.md) still describes a flat row, two overlapping posts that both fetch, and the owner on the port. Where it disagrees with this file, this file wins. [adr/0008](../../../adr/0008-owner-database-schema-splits-body-from-metadata.md) and [adr/0003](../../../adr/0003-owner-database-is-wal.md) stay proposed until the module spec adopts them.
- **Schema.** Version 1 holds three tables. `meta` is a key and value table with the owner email and the creation time. `archive` holds one row per archive: an integer id, the unique `url`, `title`, nullable `excerpt` and `site_name`, and `created_at` as UTC ISO-8601 text set by the database. `archive_body` holds the purified `html`, keyed by the archive id, with a cascading foreign key. The schema version is `PRAGMA user_version`. No derived data is stored: no plain text and no search index. Search, assets, tags, notes, and embeddings are later migrations in their own slices.
- **File.** One file per owner. Its name is the SHA-256 of the lowercased email, plus `.sqlite`. The email inside `meta` identifies it. The glossary now says that two addresses that differ only in letter case name the same owner. The file is created at page size 8192 and then set to WAL, as in adr/0003. The risk is recorded in the spec: the development image has SQLite 3.40.1, which is in the range of the WAL-reset bug that was fixed in 3.51.3. The upgrade belongs to the clean-distribution-image backlog item.
- **Directories.** The parameter `eleanor.data_dir` holds the owner files and defaults to `var/data` under the project. Lock files go in `var/lock`, outside it. The test environment sets the data directory to a temporary directory for each test.
- **Access.** Plain PDO with `pdo_sqlite`. A small class opens a connection, sets `foreign_keys` and `busy_timeout`, and runs the numbered migrations. No Doctrine and no other new database package.
- **When the file exists.** `find` and `findSummary` of an owner with no file answer absence and create nothing. `save` creates the file, runs the migrations, and writes `meta`. The write connection opens inside `save` and closes after the transaction. The file therefore exists if and only if the owner has at least one archive.
- **Store interface.** `ArchiveStore` has three methods: `findSummary(url): ?ArchiveSummary`, `find(url): ?Archive`, and `save(Archive): void`. The store stays bound to one owner. `ArchiveSummary` is a final readonly type with `url`, `title`, `excerpt`, and `siteName`. `find` joins `archive` and `archive_body`. `findSummary` reads `archive` only. `ArchiveResult` carries an `ArchiveSummary` and an `ArchiveOutcome`. On `Written`, the service builds the summary from the `Archive` it saved. Revises [ArchiveCommand.md](../design/ArchiveCommand.md).
- **`save` is an upsert.** It uses `ON CONFLICT(url) DO UPDATE` on `archive` and an upsert on `archive_body`, in one transaction. The row id and `created_at` stay as they were. It is not `INSERT OR REPLACE`, which would delete the row and cascade into the body. The service does not rely on a duplicate error. The lock is what keeps two overlapping requests from both fetching.
- **The lock.** `ArchiveLock` is an interface in the archive module, bound to one owner like the store. `ArchiveService::archive` takes the lock for the key owner plus URL, runs the lookup, the fetch, and the save inside it, and releases it in a `finally`. A different URL or owner has a different key and never waits. The waiter finds the stored archive and answers `AlreadyStored` without a fetch. The implementation uses the `symfony/lock` component with `FlockStore` on the local disk. This is a new direct Composer dependency, approved in the grill. The `ArchiveLock` interface hides it. A lock held by a request ends when the request ends or its process dies. The wait has one fixed limit of about 15 seconds, a little above the 10-second fetch timeout of the library. The limit is not configurable in this slice. `symfony/lock` does not bound a blocking wait by itself, so the implementation uses a retry store or its own retry loop, and the plan checks which.
- **Lock failures.** When the wait limit passes, the request proceeds without the lock. When the lock throws an I/O error, the implementation catches it, writes a warning on the `application` log channel, and the request proceeds without the lock. The upsert keeps the data correct in both cases. The worst result is one extra fetch.
- **Store failures.** A failed `save` becomes `ArchiveException` with a new failure kind `NotWritten`, code `archive_not_written`, status 500, one fixed sentence, and the original `PDOException` as the cause. The cause is logged by the existing listener. A failure of `findSummary` or `find` is not wrapped and answers 500 `unexpected`.
- **Service wiring.** `ArchiveServiceFactory` takes an `ArchiveLockFactory` next to the `ArchiveStoreFactory` and builds the service with the owner's store and the owner's lock. `SqliteArchiveStoreFactory` implements `ArchiveStoreFactory`. `ArchiveService` and the route keep the signatures of slice 5b apart from the changes above.
- **In-memory classes.** The in-memory shelf, store, and factory move out of `src/` into the test support code. They stay as the unit-test double and are held to the same contract test as the SQLite store.
- No change to the body DTO, the owner provider, the resolver, or the error shapes of slice 6 apart from the one new code.

## Testing Decisions

- A good test observes behavior at its seam. It does not assert a SQL string, a pragma value read back from a private call, or a lock file name.
- The highest seam stays the kernel test of `POST /api/archives`. It runs on the SQLite store with a temporary data directory and the `ArticleFetcher` double. It proves 201 and then 200 with no second fetch, 201 for a different URL, a title with a quote and a non-ASCII letter, and that a second kernel built on the same directory still answers 200. It proves that a failed fetch stores nothing and that the same URL archives afterwards. It proves 500 `archive_not_written` with a data directory that is not writable, with the fixed sentence and no leaked text.
- A store contract test class runs one set of cases against the in-memory store and the SQLite store: absence, `save` then `find` and `findSummary`, the summary without the HTML, an upsert that keeps the same row, two stores for one owner sharing rows, and a store for another owner not seeing them. The SQLite case adds: a lookup creates no file, the first `save` creates one, two owners get two files, and the same email in a different letter case names the same file.
- `ArchiveService` unit tests use a fake store and a fake lock. They prove that the lookup, the fetch, and the save run inside the lock, that a waiter returns `AlreadyStored` without a fetch, that the lock is released when the fetch throws, and that a lock that fails to take still lets the archive proceed.
- The lock implementation has a unit test on a temporary directory. Two keys do not block each other. One key blocks a second holder until the first releases. The wait limit lets a second holder proceed. An unwritable lock directory logs a warning and does not throw. The tests do not sleep for the full limit: the limit is a constructor argument of the implementation.
- `ArchiveServiceFactoryTest` gets the case from the slice 5 leftover: a service for a second owner gets `Written` for a URL the first owner already stored.
- Prior art: `ArchiveServiceTest`, `InMemoryArchiveStoreTest`, and `CreateArchiveRouteTest`.
- A socket test, a browser test, and a real network fetch are not seams. A true two-process race is not automated. The lock is proved at its own seam and the service at its own.
- `./bin/quality` passing for `web-app` is an acceptance check, not a test.

## Out of Scope

- Text search, a plain-text column, a full-text index, and any HTML-to-text step. They are a later migration, per adr/0008.
- Assets and image bytes, tags, notes, highlights, and embeddings.
- Refreshing a changed page, and an `updated_at` column. `save` as an upsert is what a refresh will use later.
- An idempotency key.
- A negative cache for a failing URL. Concurrent retries of a slowly failing URL serialize behind the lock, each up to the wait limit.
- A configurable lock wait limit belongs to [Limits and configuration](../../../backlog/limits-and-configuration.md). `request_terminate_timeout` belongs to [Runtime](../../../backlog/runtime.md).
- A second owner signed in at the same time, URL normalization, and the firewall and token slices.
- Upgrading SQLite in the image.
- A backup or export command. The file is the export once no connection is open, or a snapshot taken with `VACUUM INTO`.

## Further Notes

- [feature.md](../feature.md) records that the archive lock is part of slice 7, and that this contract revises `ArchiveStore` and `ArchiveResult` from slice 5.
- The module spec is [design/SqliteStore.md](../design/SqliteStore.md). The plan is [plans/2026-10-18-sqlite-store.md](../plans/2026-10-18-sqlite-store.md), waiting for review.
- The slice 5 leftover on a second owner is closed here. Delete its line from `feature.md` when the slice is done.
