# Archive log

Module design for the lines `ArchiveService` writes while it archives one URL. This spec adds no slice and no glossary term. It revises [Logging.md](Logging.md), [ArchiveCommand.md](ArchiveCommand.md), and [SqliteStore.md](SqliteStore.md). No ADR changes. Revised 2026-10-22: a failed `save` reaches the service as `ArchiveException` with `NotWritten`. The service logs `archive.failed` and rethrows that exception. It does not catch `DirectoryException` or `\PDOException`.

`application` stays the HTTP round: `http.request.start`, `http.request.finish`, and `http.request.failed`. `domain` is the archive command. `ArchiveService` is the first writer on `domain`.

## Revises

[Logging.md](Logging.md) left a writer on `domain` out of scope, and left the request-id processor until a later `domain` line. This spec adds both. The HTTP lines in that spec stay.

[ArchiveCommand.md](ArchiveCommand.md) left a log line for an archive out of scope. The lines below are that log.

[SqliteStore.md](SqliteStore.md) gives `ArchiveStore` `findSummary`, `find`, and `save`. This spec adds `location()`.

## Module

```text
Request/
  RequestIdProcessor.php
Archive/
  ArchiveService.php
  ArchiveServiceFactory.php
  ArchiveStore.php
  Sqlite/
    SqliteArchiveStore.php
```

`RequestIdProcessor` is new. The other types gain the logger, the channel, or `location()`.

### `Yumo\Eleanor\Request\RequestIdProcessor`

Final class. It depends on `RequestStack`. `__invoke` takes a Monolog `LogRecord` and returns one.

The installed Monolog bundle has no `processors` key on a handler. The service is tagged `monolog.processor` with `handler: eleanor`, and the compiler pushes it onto that handler in `dev`, `test`, and `prod`. That handler already accepts only `application` and `domain`, so Symfony's channels do not receive it.

On each record the processor reads the current request. When that request holds an `EnrichedRequest`, it sets `extra.request_id` to `requestId`. No current request, or a request without that attribute, leaves `extra` without the key. The processor does not throw for either case. It does not copy `userAgent`, `referer`, the URL, or the owner. It does not add an argument to `ArchiveService` or to any other caller. A `request_id` already present in `context` stays there. The join across Eleanor's lines is `extra.request_id`. The HTTP lines keep `request_id` in `context` as [Logging.md](Logging.md) wrote it.

### `Yumo\Eleanor\Archive\ArchiveService`

`#[Exclude]`. The constructor gains a `LoggerInterface`. `ArchiveServiceFactory` carries `#[WithMonologChannel('domain')]` and passes that logger through `create`. The factory stays a container service. The service does not.

A failed log write is caught in the service and not rethrown. The archive outcome stays the one the command already returns.

The message is the event name. The values sit in `context`. The lines do not carry the article HTML, the title, the email, a bearer token, or an exception. A 500 still logs the exception on `application` as `http.request.failed`.

| Message | Level | When | Context |
| --- | --- | --- | --- |
| `archive.start` | info | Inside the lock, before `findSummary` | `url` |
| `archive.already_stored` | info | A summary is already stored | `url` |
| `archive.fetch` | info | No summary, before the fetch | `url` |
| `archive.written` | info | `save` returned | `url`, `store` |
| `archive.failed` | warning | An `ArchiveException` is about to leave the service | `url`, `code` |

`url` is `$url->toString()`. `code` is the `ArchiveFailure` value. `store` is `ArchiveStore::location()` when that value is a string. The key is absent when `location()` is `null`. `store` is present on `archive.written` and on `archive.failed` when `code` is `archive_not_written`. A fetch failure and an empty page do not add `store`.

`archive.start` is written before `findSummary`. A lookup that throws still has the URL on `domain`. That throw is not an `ArchiveException`, so the service does not write `archive.failed` for it.

### `Yumo\Eleanor\Archive\ArchiveStore::location`

`location(): ?string` is the file the store writes, or `null` when the store has no file. It does not open the file and it does not throw.

`SqliteArchiveStore` returns the path `SqliteArchiveStoreFactory` already builds: the data directory, a slash, the SHA-256 of the owner email, and `.sqlite`. The email is not a log field. The in-memory store returns `null`.

## Data flow

1. `LogApiRequest` stores `EnrichedRequest` on the request, then writes `http.request.start`.
2. `ArchiveService::archive` takes the lock and writes `archive.start`.
3. A stored summary writes `archive.already_stored` and returns. No fetch, no `store`.
4. A miss writes `archive.fetch`, then fetches. A mapped failure writes `archive.failed` with `url` and `code`, and throws.
5. `save` either writes `archive.written` with `url` and `store`, or an `ArchiveException` with `NotWritten` is rethrown after `archive.failed` with `code` `archive_not_written` and `store`. The service does not map `DirectoryException` or `\PDOException`. Before 2026-10-22 those two exceptions were what the service caught.
6. The processor has set `extra.request_id` on each of those records from the request stored in step 1.
7. `http.request.finish` closes the round. A status of 500 or higher also writes `http.request.failed` on `application`.

## Test

`tests/Request/RequestIdProcessorTest.php` does not boot the kernel. A request that holds an `EnrichedRequest` sets `extra.request_id`. A stack with no request, and a request without the attribute, leave that key absent. `userAgent` and `referer` are not copied. A `request_id` already present in `context` stays there.

`tests/Archive/ArchiveServiceTest.php` records the logger. A hit writes `archive.start` and `archive.already_stored`. A miss writes `archive.start`, `archive.fetch`, and `archive.written`. A mapped fetch failure writes `archive.failed` with `code` and without `store`. An `ArchiveException` with `NotWritten` from `save` writes `archive.failed` with `code` `archive_not_written` and `store` equal to `location()`, and the same exception escapes. A `DirectoryException` or a `\PDOException` from `save` writes no `archive.failed`. A store whose `location()` is `null` omits `store`. A logger that throws does not replace the outcome.

`ArchiveStoreContractTest` covers `location()`. The SQLite store returns the path the factory builds for that owner. The in-memory store returns `null`.

`CreateArchiveRouteTest` and `LogApiRequestTest` stay. They do not assert these lines. The article-reader suite is not a seam. A browser check is not a seam.

## Out of scope

Wiring a logger into `OrchestratorFactory`, and any change to the library's stage lines. Moving `archive.lock.not_taken` and `archive.lock.not_released` off `application`. A line when the lock wait expires. Masking an owner email. Copying User-Agent or Referer onto the domain lines. A new glossary term.
