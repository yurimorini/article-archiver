# Archive command

Module design for [archive-command.md](../archive-command.md). The decisions in that contract are closed, including the two revisions recorded in [feature.md](../feature.md) under Slice merge: `ArticleFetcher` replaces the orchestrator as the service's dependency, and `OwnerProvider` replaces the controller's own reading of the owner parameter. This spec adopts [adr/0002](../../../adr/0002-second-post-returns-stored-archive.md), [adr/0004](../../../adr/0004-archive-lookup-is-per-owner.md), and [adr/0009](../../../adr/0009-fetch-seam-returns-an-article.md). [adr/0007](../../../adr/0007-archive-body-mapped-by-map-request-payload.md) stays as the payload slice adopted it. [adr/0001](../../../adr/0001-archive-outcome-status.md) waits for the problem-document slice. [adr/0008](../../../adr/0008-owner-database-schema-splits-body-from-metadata.md) is adopted by [SqliteStore.md](SqliteStore.md). This slice adds no glossary term. Revised 2026-10-22: `ArticleFetcher` returns an `Article` and throws `ArchiveException`. `ArticleReaderAdapter` maps the library outcome. `ArchiveService` no longer reads library types and no longer takes `ArchiveFailureMapper`. The glossary term Article is that body. Revised 2026-10-22, store save: `ArchiveStore::save` throws `ArchiveException` with `NotWritten` when the archive cannot be stored. `ArchiveService` logs that failure and rethrows the same exception. It does not catch `DirectoryException` or `\PDOException`. A failure of `findSummary` or `find` stays unwrapped. [SqliteStore.md](SqliteStore.md) and [ArchiveLog.md](ArchiveLog.md) record the same move. This spec is slice 5. Revised by [SqliteStore.md](SqliteStore.md): `Owner` lowercases the email, `ArchiveResult` carries an `ArchiveSummary`, `ArchiveStore` gains `findSummary`, `save` is an upsert, `ArchiveService` takes an `ArchiveLock`, and the in-memory classes move to the tests. Revised by [UrlPointer.md](UrlPointer.md): the URL those ports take is a `UrlPointer`, and the service no longer trims it.

This slice replaces the controller in [PayloadAndMapper.md](PayloadAndMapper.md): `create` is no longer the stub. The class got a constructor in this slice. Slice 5b removed it, and the action receives the `ArchiveService` as an argument ([OwnerScopedService.md](OwnerScopedService.md)).

## Module

Three namespaces under `src/`.

### `Yumo\Eleanor\Archive`

The archive command. It does not know Symfony, HTTP, or SQLite.

- `Owner`: final readonly class. One public `string $email`. The constructor stores `mb_strtolower($email, 'UTF-8')` (revised by [SqliteStore.md](SqliteStore.md)). `#[Exclude]`.
- `Archive`: final readonly class. Public `string $url`, `string $title`, `?string $excerpt`, `?string $siteName`, `string $html`. `#[Exclude]`.
- `ArchiveSummary`: final readonly class. Public `string $url`, `string $title`, `?string $excerpt`, `?string $siteName`. No HTML. `fromArchive(Archive $archive): self` copies those fields and leaves the HTML out. Added by [SqliteStore.md](SqliteStore.md). `#[Exclude]`.
- `ArchiveOutcome`: enum with `Written` and `AlreadyStored`. `#[Exclude]`.
- `ArchiveResult`: final readonly class. Public `ArchiveSummary $summary` and `ArchiveOutcome $outcome` (revised by [SqliteStore.md](SqliteStore.md); before that revision the first property was `Archive $archive`). `#[Exclude]`.
- `ArchiveException`: final class extending `\RuntimeException`. The constructor takes an `ArchiveFailure`, an optional cause, and an optional upstream status. `#[Exclude]`. Slice 6 added the failure and the upstream status. Before that slice the constructor took a message and an optional previous, and it had no reason enum.
- `ArchiveStore`: interface. `findSummary(UrlPointer $url): ?ArchiveSummary`, `find(UrlPointer $url): ?Archive`, and `save(Archive $archive): void` (revised by [UrlPointer.md](UrlPointer.md); before that revision the lookup methods took `string $url`). `save` is an upsert (revised by [SqliteStore.md](SqliteStore.md); before that revision there was no `findSummary` and `save` was not an upsert). A failure of `save` throws `ArchiveException` with `NotWritten`. A failure of `findSummary` or `find` is not wrapped (revised 2026-10-22; before that revision the service caught `DirectoryException` and `\PDOException` from `save` and built the `ArchiveException`). A store belongs to one owner. No method takes the owner.
- `ArchiveStoreFactory`: interface. `create(Owner $owner): ArchiveStore`.
- `Article`: final readonly class. Public `string $title`, `?string $excerpt`, `?string $siteName`, `string $html`. No URL and no warnings. `#[Exclude]`. Added 2026-10-22.
- `ArticleFetcher`: interface. `fetch(UrlPointer $url): Article`. A failure throws `ArchiveException` (revised 2026-10-22; before that revision the return was the library's `PipelineSuccess|PipelineNoContent` and the service read those types). The argument is a `UrlPointer` (revised by [UrlPointer.md](UrlPointer.md); before that revision the argument was a string).
- `ArticleReaderAdapter`: final class implementing `ArticleFetcher`. It takes the library `Orchestrator`. `fetch` calls `fetchArticle($url->toString())`. A `PipelineSuccess` becomes an `Article`: `title`, `excerpt`, and `siteName` come from the document through `PlainText::raw()`, and `excerpt` and `siteName` stay null when the document has none. `html` is the document HTML. Warnings are not read. A `PipelineNoContent` and an `OrchestratorException` become `ArchiveException` through `ArchiveFailureMapper`, which this adapter constructs. Any other throwable passes through. Before 2026-10-22 the adapter returned the library outcome unchanged.
- `ArchiveFailureMapper`: final class, `#[Exclude]`. It maps a library failure to `ArchiveException`. Only `ArticleReaderAdapter` constructs it. `ArchiveService` does not take it (revised 2026-10-22).
- `ArchiveLock`: interface. `exclusive(UrlPointer $url, callable $action): mixed` (revised by [UrlPointer.md](UrlPointer.md)). Added by [SqliteStore.md](SqliteStore.md).
- `ArchiveLockFactory`: interface. `create(Owner $owner): ArchiveLock`. Added by [SqliteStore.md](SqliteStore.md).
- `ArchiveService`: final class, no interface, `#[Exclude]`. The constructor takes `ArchiveStore`, `ArticleFetcher`, and `ArchiveLock` (revised 2026-10-22; [SqliteStore.md](SqliteStore.md) had added `ArchiveFailureMapper` after the lock, and before that revision the constructor took the store and the fetcher only). It does not hold the `Owner`, because the store and the lock are already bound to it. It does not name a library type.
- `ArchiveServiceFactory`: final class, no state. The constructor takes one `ArticleFetcher`, one `ArchiveStoreFactory`, and one `ArchiveLockFactory`. `create(Owner $owner): ArchiveService` returns a service with that owner's store and lock (revised by [SqliteStore.md](SqliteStore.md)).

`ArchiveService::archive(UrlPointer $url): ArchiveResult`, as revised by [UrlPointer.md](UrlPointer.md) (before that revision the argument was a string and step 1 trimmed it):

1. The pointer already holds the stored name. This method does not trim.
2. `exclusive($url)` around the rest. The lock, already bound to the owner, keys that call with the email, a NUL byte, and `$url->toString()`.
3. `findSummary($url)`. A hit returns `AlreadyStored` with that summary. The fetcher is not called.
4. A miss calls `fetch($url)`. The fetcher returns an `Article` or throws `ArchiveException`. Any other throwable passes through the fetcher. Nothing is saved. Before 2026-10-22 the service itself mapped `OrchestratorException` and `PipelineNoContent`.
5. The `Article` becomes `Archive`. `url` is `$url->toString()`. `title`, `excerpt`, `siteName`, and `html` are copied from the `Article`.
6. `save($archive)`. The store throws `ArchiveException` with `NotWritten` when the archive cannot be stored. The service logs `archive.failed` and rethrows that same exception. Any other throwable from `save` passes through. Before 2026-10-22 the service caught `DirectoryException` and `\PDOException` and built the `ArchiveException` (that catch was added by [SqliteStore.md](SqliteStore.md); before the catch, only a `\PDOException` was translated). `Written` carries a summary built from that `Archive`.

`https://example.com/a` and `https://example.com/a/` are two archives. A leading or trailing NUL is not part of the stored name. Two overlapping first POSTs of one URL are owned by [SqliteStore.md](SqliteStore.md).

### `Yumo\Eleanor\Archive\InMemory`

Moved by [SqliteStore.md](SqliteStore.md) to `tests/Support/InMemory/` under `Yumo\Eleanor\Tests\Support\InMemory`. They are the contract-test double, they implement `findSummary`, and they are not container services. Before that move they lived in `src/Archive/InMemory/` as the temporary stand-in.

- `InMemoryArchiveShelf`: plain test class, not a container service. One instance is shared by the stores a test creates. It holds `array<string, array<string, Archive>>`, owner email then URL. It has `find(Owner $owner, string $url): ?Archive` and `save(Owner $owner, Archive $archive): void`. It stands in for the directory of owner files.
- `InMemoryArchiveStore`: implements `ArchiveStore`. The constructor takes an `Owner` and the shelf. `find` and `save` delegate to the shelf with that owner. `#[Exclude]`.
- `InMemoryArchiveStoreFactory`: implements `ArchiveStoreFactory`. It takes the shelf and has no other state. `create` returns `new InMemoryArchiveStore($owner, $this->shelf)`.

Two stores for one owner share rows through the shelf. A store for another owner does not see them.

### `Yumo\Eleanor\Api`

- `Api\Owner\OwnerProvider`: interface. `owner(): Owner`. It answers for the current call.
- `Api\Owner\ConfiguredOwnerProvider`: final class. The constructor takes the owner email string. `owner()` returns `new Owner($email)`.
- `Api\Controller\CreateArchiveController` keeps the route `create_archive`, path `/archives`, method POST, format `json`. Revised by [OwnerScopedService.md](OwnerScopedService.md) (slice 5b): the class has no constructor, and `ArchiveServiceResolver` passes the owner's `ArchiveService` as an action argument. Before that revision the constructor took `ArchiveServiceFactory` and `OwnerProvider`. The controller never read the parameter. The action keeps the `MapRequestPayload` signature of the payload slice.
  - The DTO does not change. `$body->url` stays a string.
  - `$result = $archives->archive(new UrlPointer($body->url ?? throw new \LogicException('NotNull guarantees url.')))`, where `$archives` is the action argument (revised by [UrlPointer.md](UrlPointer.md) and by slice 5b). Before the pointer revision the action passed the body string through. Before slice 5b: `$this->archives->create($this->owners->owner())->archive($url)`.
  - The status is 201 for `Written` and 200 for `AlreadyStored`.
  - The success body reads `$result->summary->url` and `$result->summary->title` (revised by [SqliteStore.md](SqliteStore.md); before that revision the fields were `$result->archive->url` and `$result->archive->title`). Slice 6 writes that body through `ArchiveResponse` and `Reply`.
  - It does not catch `ArchiveException`.

## Configuration

`config/services.yaml`:

- Parameter `eleanor.owner_email` with a fixed placeholder value, `owner@example.com`. The README tells the reader to change it.
- `Yumo\ArtcleReader\Orchestrator\Orchestrator` is a service with `factory: ['Yumo\ArtcleReader\Orchestrator\OrchestratorFactory', 'create']` and no arguments. The logger stays the library's `NullLogger`. This slice adds no log line.
- `ConfiguredOwnerProvider` takes `$email: '%eleanor.owner_email%'`. The `OwnerProvider` interface has that one implementation, so the alias is automatic. The firewall slice replaces that alias.
- `ArticleFetcher`, `ArchiveStoreFactory`, and `OwnerProvider` each have one implementation in `src/`, so the aliases are automatic. [SqliteStore.md](SqliteStore.md) replaces the store implementation with `SqliteArchiveStoreFactory` and adds `ArchiveLockFactory`. Before that, `InMemoryArchiveShelf` was the shared stand-in.
- The existing `Yumo\Eleanor\` resource registers everything else. The `#[Exclude]` classes above are not registered.

`config/services_test.yaml`, which the kernel loads in the `test` environment:

- Parameter `eleanor.owner_email` set to a test address.
- `ArticleFetcher` is an alias of `Yumo\Eleanor\Tests\Support\FakeArticleFetcher`, a public service, so the test reads it from `static::getContainer()`.

No new Composer package. `yumo/article-reader` is already a direct requirement and carries the library.

## Data flow

`POST /api/archives` with `{"url":"https://example.com/a"}`:

1. The resolver maps and validates the body, as before.
2. `ArchiveServiceResolver` asks `OwnerProvider` for the `Owner` and `ArchiveServiceFactory` for the service of that owner, and passes it to the action (revised by slice 5b).
3. `ArchiveService::archive` trims the URL, takes the lock, finds nothing, fetches through `ArticleFetcher`, builds and saves the `Archive`, and returns `Written` (revised by [SqliteStore.md](SqliteStore.md)).
4. The controller answers 201 with `{"url":"https://example.com/a","title":"..."}` from the summary.

A second POST of the same string finds the summary, does not fetch, and answers 200 with the same JSON.

## Errors

Every failure after the body map is an `ArchiveException` from the service. The controller does not catch it. Slice 6 answers it with the error document. The 2026-10-22 revision does not change those codes. A route test that registers a fetch failure registers an `ArchiveException`, not a library throwable. Before slice 6, Symfony's own error response was the answer, and the tests asserted only that the body was not the success JSON.

A failed fetch stores nothing, so the next POST of that URL fetches again. This slice has no negative cache.

`""`, `" "`, and `not-a-url` pass the body map. In production the library's `UrlGuard` rejects them inside the orchestrator, and the adapter wraps that failure as `ArchiveException`. In the tests the fake throws `ArchiveException` for a URL with no registered outcome.

The 400, 415, and 405 refusals stay as the payload slice left them.

## Test

Support class `Yumo\Eleanor\Tests\Support\FakeArticleFetcher` in `tests/Support/`:

- `willReturn(string $url, Article $article)` (revised 2026-10-22; before that revision the outcome was `PipelineSuccess|PipelineNoContent`).
- `willThrow(string $url, ArchiveException $failure)` (revised 2026-10-22; before that revision the argument was any `\Throwable`).
- `fetchCount(?string $url = null): int`.
- A URL with no registered outcome throws `ArchiveException` with `ArchiveFailure::UrlRejected`. Before 2026-10-22 the fake threw `OrchestratorException(OrchestratorError::UrlGuard)` and the service mapped that to the same failure.

A small builder in `tests/Support/` makes an `Article` from a title, an optional excerpt and site name, and HTML. It does not build a library document.

Unit tests, no kernel, in `tests/Archive/` and `tests/Api/Owner/`:

- `OwnerTest`: `Ada@Example.com` is stored as `ada@example.com` (revised by [SqliteStore.md](SqliteStore.md)).
- `ConfiguredOwnerProviderTest`: `owner()` returns an `Owner` with the configured email.
- `InMemoryArchiveStoreTest`: an unknown URL is absent. `save` then `find` returns the same archive. A store for another owner from the same shelf does not see it. A second store for the same owner does.
- `InMemoryArchiveStoreFactoryTest`: two `create` calls for the same owner share rows, and another owner does not.
- `ArchiveServiceFactoryTest`: with the real in-memory factory and the fake, the second service for the same owner returns `AlreadyStored` for what the first wrote.
- `ArchiveServiceTest`: the cases listed in the contract's Testing Decisions. In addition, `url` is the string passed in, and a failed fetch leaves the next call free to fetch again. The test builds an `Article`, not a library document.

HTTP test: `CreateArchiveRouteTest` stays the HTTP seam, and calls `$client->disableReboot()` so the shelf survives between two requests. It reads the fake and its `fetchCount`. Cases:

- A new URL: route `create_archive`, 201, `application/json`, `{"url":...,"title":...}` with `url` first, no email.
- The same URL again: 200, the same body, one fetch.
- A different URL: 201, a second fetch.
- A title with a quote and a backslash decodes to the same title.
- An empty title gives `"title":""`.
- `""`, `" "`, `not-a-url`, an `ArchiveException` for an empty article, and any other thrown `ArchiveException`: not the success JSON, not `application/problem+json`.
- A query string, `Accept: text/html`, an extra property, and a charset on the content type: still the archive.
- The 400, 415, GET, HEAD, and kernel boot cases stay.

`LogApiRequestTest` also posts `https://example.test/a` and asserts 200 and `{"ok":true}` in `test_post_stores_the_sent_id_agent_and_referer`. The `postArchives` helper of that file registers an outcome for that URL in the fake, so every test in the file reaches a success. That test then asserts 201 and the success JSON. It stays a logging test.

`packages/web-app/README.md`: the curl shows the 201 body of `url` and `title`, and says where the owner email is set. The curl is a manual check. It is not a second automated seam.

`./bin/quality` passing is an acceptance check, not a test.

`ArticleReaderAdapter` is tested with an `Orchestrator` built from test collaborators. `Orchestrator` is `final`, and its constructor is the library's test seam. Those tests cover one success, both empty reasons, each library failure code, a failure with no finer reason, the upstream status number, and a throwable that is not a library failure. The success asserts the `Article` fields and that a warning is absent. A thrown library failure keeps its cause. An empty page has no cause. Any other throwable passes through. They replace `ArchiveFailureMapperTest` as the test surface for that translation. The command tests do not import a library type.

## Out of scope

The contract's Out of Scope list stands. Specifically for this module: a store that survives a process, a negative cache, URL normalization, the authenticated `OwnerProvider`, and any change to the library. The domain lines for an archive live in [ArchiveLog.md](ArchiveLog.md).
