# URL pointer

Module design for the URL the archive command names a page with. This spec revises [SqliteStore.md](SqliteStore.md) and [ArchiveCommand.md](ArchiveCommand.md). It adds no slice. The body DTO in [PayloadAndMapper.md](PayloadAndMapper.md) stays a string. No ADR changes: none of them decides how a URL is named, and the stored column stays `TEXT`.

The glossary term is [URL pointer](../../../GLOSSARY.md). This spec is the type of that term. It does not add a term.

## Revises

[SqliteStore.md](SqliteStore.md) trims leading and trailing NUL bytes inside `ArchiveService::archive` and then passes that string to the lock, the fetch, and the store. [ArchiveCommand.md](ArchiveCommand.md) says the same. A later `find` can skip that line. This spec moves the trim into one type, and the ports take that type.

Rejected: trimming only inside the lock. The stored URL would still be the raw string. Rejected: an object that keeps both the raw URL and the trimmed URL. Nothing in this command reads the raw URL after the pointer exists, and `toString()` would still let a caller pass the raw string into `find`.

## Module

One class opens in `src/Archive/`, beside the ports. No new folder.

```text
Archive/
  UrlPointer.php
```

### `Yumo\Eleanor\Archive\UrlPointer`

Final readonly class. `#[Exclude]`. Not a container service.

The constructor takes the string the caller was given. It stores `trim($url, "\0")`. A NUL in the middle stays. Whitespace stays. The constructor does not judge syntax, scheme, or host. An empty string after the trim is a pointer. The fetch and the URL guard still decide what that string means.

`toString(): string` returns the stored string. That is the only way out. The class has no `__toString`, so a concatenation cannot pick the string by accident. It has no other field. The raw input is not kept.

Two pointers with the same `toString()` name the same page. `https://example.com/a` and `https://example.com/a/` stay two pointers. `\0https://example.com/a\0` and `https://example.com/a` are one pointer.

### Ports

These signatures replace the `string $url` parameters named in [SqliteStore.md](SqliteStore.md) and [ArchiveCommand.md](ArchiveCommand.md). `save(Archive $archive): void` stays. `Archive::$url` and `ArchiveSummary::$url` stay strings. The service copies `toString()` into `Archive` when it builds one. The summary copies that field through `ArchiveSummary::fromArchive`.

- `ArchiveService::archive(UrlPointer $url): ArchiveResult`. The method does not trim. The lock, the fetch, and `findSummary` receive the same pointer.
- `ArchiveLock::exclusive(UrlPointer $url, callable $action): mixed`. The callable still takes no arguments. `FlockArchiveLock` builds the key as the owner email, a NUL byte, and `$url->toString()`.
- `ArticleFetcher::fetch(UrlPointer $url)`. `ArticleReaderAdapter` passes `$url->toString()` to the library.
- `ArchiveStore::findSummary(UrlPointer $url)` and `find(UrlPointer $url)`. `SqliteArchiveStore` binds `$url->toString()` in the SQL parameter. The in-memory shelf stays keyed by that string. The store calls `toString()` at that boundary.

### `Yumo\Eleanor\Api`

`CreateArchiveBody::$url` stays `?string`. The controller is the edge that builds the pointer:

`$archives->archive(new UrlPointer($body->url ?? throw new \LogicException('NotNull guarantees url.')))`.

The route, the status, and the success body stay. The body still returns `$result->summary->url`, which is the stored string.

## Data flow

1. The controller builds one `UrlPointer` from the body string.
2. `archive` takes the lock on that pointer, then `findSummary` with that same pointer.
3. A miss fetches that pointer and builds `Archive` with `toString()` as `url`.
4. `save` writes that archive. The SQL `url` column is the stored string.
5. A later `find` or `findSummary` takes a `UrlPointer`. The lookup string is `toString()`.

A posted `\0https://example.com/a\0` is the same pointer, the same lock key, and the same stored URL as `https://example.com/a`. A NUL in the middle stays part of the name. A surrounding space stays part of the name.

## Test

`UrlPointer` has its own test: leading and trailing NUL bytes are gone, a NUL in the middle stays, a surrounding space stays, and `https://example.com/a/` stays different from `https://example.com/a`.

`ArchiveServiceTest` builds a `UrlPointer` at the call. The NUL cases assert the pointer the lock and the store receive, not a trim inside the service. The other service cases pass a pointer whose `toString()` is the URL they already use.

`ArchiveStoreContractTest`, the in-memory store tests, `FlockArchiveLockTest`, and `ArchiveServiceFactoryTest` pass a `UrlPointer` into `find`, `findSummary`, `exclusive`, and `archive`. Route tests keep a JSON string body. The controller test seam is the route.

## Out of scope

Keeping the raw URL. `__toString`. Normalizing a trailing slash, letter case, or scheme. Stripping a NUL from the middle. Changing the SQL column. Moving the URL guard into this type. A second command that finds an archive. The firewall and token slices.
