# Archive command

Module design for [archive-command.md](../archive-command.md). The decisions in that contract are closed, including the two revisions recorded in [feature.md](../feature.md) under Slice merge: `ArticleFetcher` replaces the orchestrator as the service's dependency, and `OwnerProvider` replaces the controller's own reading of the owner parameter. This spec adopts [adr/0002](../../../adr/0002-second-post-returns-stored-archive.md) and [adr/0004](../../../adr/0004-archive-lookup-is-per-owner.md). [adr/0007](../../../adr/0007-archive-body-mapped-by-map-request-payload.md) stays as the payload slice adopted it. [adr/0001](../../../adr/0001-archive-outcome-status.md) waits for the problem-document slice. [adr/0008](../../../adr/0008-owner-database-schema-splits-body-from-metadata.md) waits for the SQLite slice. This slice adds no glossary term. This spec is slice 5.

This slice replaces the controller in [PayloadAndMapper.md](PayloadAndMapper.md): `create` is no longer the stub. The class got a constructor in this slice. Slice 5b removed it, and the action receives the `ArchiveService` as an argument ([OwnerScopedService.md](OwnerScopedService.md)).

## Module

Three namespaces under `src/`.

### `Yumo\Eleanor\Archive`

The archive command. It does not know Symfony, HTTP, or SQLite.

- `Owner`: final readonly class. One public `string $email`. `#[Exclude]`.
- `Archive`: final readonly class. Public `string $url`, `string $title`, `?string $excerpt`, `?string $siteName`, `string $html`. `#[Exclude]`.
- `ArchiveOutcome`: enum with `Written` and `AlreadyStored`. `#[Exclude]`.
- `ArchiveResult`: final readonly class. Public `Archive $archive` and `ArchiveOutcome $outcome`. `#[Exclude]`.
- `ArchiveException`: final class extending `\RuntimeException`. The constructor takes a message and an optional `?\Throwable $previous`. It has no reason enum. The problem-document slice adds what it needs. `#[Exclude]`.
- `ArchiveStore`: interface. `find(string $url): ?Archive` and `save(Archive $archive): void`. A store belongs to one owner. Neither method takes the owner.
- `ArchiveStoreFactory`: interface. `create(Owner $owner): ArchiveStore`.
- `ArticleFetcher`: interface. `fetch(string $url): PipelineSuccess|PipelineNoContent`. The two types are the library's. The service reads them.
- `ArticleReaderAdapter`: final class implementing `ArticleFetcher`. It takes the library `Orchestrator` and returns `fetchArticle($url)`. It adds no logic.
- `ArchiveService`: final class, no interface, `#[Exclude]`. The constructor takes `ArchiveStore` and `ArticleFetcher`. It does not hold the `Owner`, because the store is already bound to it.
- `ArchiveServiceFactory`: final class, no state. The constructor takes one `ArticleFetcher` and one `ArchiveStoreFactory`. `create(Owner $owner): ArchiveService` returns `new ArchiveService($this->stores->create($owner), $this->fetcher)`.

`ArchiveService::archive(string $url): ArchiveResult`:

1. `find($url)`. A hit returns `ArchiveResult` with that archive and `AlreadyStored`. The fetcher is not called.
2. A miss calls `fetch($url)`. A `\Throwable` becomes `ArchiveException` with that throwable as `previous`. Nothing is saved.
3. `PipelineNoContent` becomes `ArchiveException`. Nothing is saved. This covers both a page with no article and an article that is empty after purification.
4. `PipelineSuccess` becomes `Archive`. `url` is the string passed in. `title`, `excerpt`, and `siteName` come from `$success->document` through `PlainText::raw()`, and `excerpt` and `siteName` stay null when the document has none. `html` is `$success->document->html`. Warnings are not read.
5. `save($archive)`, then `ArchiveResult` with `Written`.

The key is the URL string as given. `https://example.com/a` and `https://example.com/a/` are two archives. This slice does not normalize. Two concurrent first POSTs of one URL are not a case here: the in-memory store has no concurrency, and the SQLite slice owns the race recorded in [Storage.md](../research/Storage.md).

### `Yumo\Eleanor\Archive\InMemory`

A temporary stand-in for the SQLite slice. Each class says so in its docblock. The SQLite slice removes these classes from `src/` or moves them to `tests/`. Nothing outside `config/services.yaml` names them.

- `InMemoryArchiveShelf`: container service, shared. It holds `array<string, array<string, Archive>>`, owner email then URL. It has `find(Owner $owner, string $url): ?Archive` and `save(Owner $owner, Archive $archive): void`. It stands in for the directory of owner files.
- `InMemoryArchiveStore`: implements `ArchiveStore`. The constructor takes an `Owner` and the shelf. `find` and `save` delegate to the shelf with that owner. `#[Exclude]`.
- `InMemoryArchiveStoreFactory`: implements `ArchiveStoreFactory`. It takes the shelf and has no other state. `create` returns `new InMemoryArchiveStore($owner, $this->shelf)`.

Two stores for one owner share rows through the shelf. A store for another owner does not see them.

### `Yumo\Eleanor\Api`

- `Api\Owner\OwnerProvider`: interface. `owner(): Owner`. It answers for the current call.
- `Api\Owner\ConfiguredOwnerProvider`: final class. The constructor takes the owner email string. `owner()` returns `new Owner($email)`.
- `Api\Controller\CreateArchiveController` keeps the route `create_archive`, path `/archives`, method POST, format `json`. Revised by [OwnerScopedService.md](OwnerScopedService.md) (slice 5b): the class has no constructor, and `ArchiveServiceResolver` passes the owner's `ArchiveService` as an action argument. Before that revision the constructor took `ArchiveServiceFactory` and `OwnerProvider`. The controller never read the parameter. The action keeps the `MapRequestPayload` signature of the payload slice.
  - `$url = $body->url ?? throw new \LogicException('NotNull guarantees url.')`. The DTO does not change.
  - `$result = $archives->archive($url)`, where `$archives` is the action argument (revised by slice 5b). Before that revision: `$this->archives->create($this->owners->owner())->archive($url)`.
  - The status is 201 for `Written` and 200 for `AlreadyStored`.
  - The body is `json_encode(['url' => $archive->url, 'title' => $archive->title], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`. Without `JSON_UNESCAPED_SLASHES` the URL would read `https:\/\/example.com\/a`. `JsonResponse::fromJsonString($json, $status)` writes the response. The content type stays `application/json`.
  - It does not catch `ArchiveException`.

## Configuration

`config/services.yaml`:

- Parameter `eleanor.owner_email` with a fixed placeholder value, `owner@example.com`. The README tells the reader to change it.
- `Yumo\ArtcleReader\Orchestrator\Orchestrator` is a service with `factory: ['Yumo\ArtcleReader\Orchestrator\OrchestratorFactory', 'create']` and no arguments. The logger stays the library's `NullLogger`. This slice adds no log line.
- `ConfiguredOwnerProvider` takes `$email: '%eleanor.owner_email%'`. The `OwnerProvider` interface has that one implementation, so the alias is automatic. The firewall slice replaces that alias.
- `ArticleFetcher`, `ArchiveStoreFactory`, and `OwnerProvider` each have one implementation in `src/`, so the aliases are automatic. `InMemoryArchiveShelf` is shared, which is the default.
- The existing `Yumo\Eleanor\` resource registers everything else. The `#[Exclude]` classes above are not registered.

`config/services_test.yaml`, which the kernel loads in the `test` environment:

- Parameter `eleanor.owner_email` set to a test address.
- `ArticleFetcher` is an alias of `Yumo\Eleanor\Tests\Support\FakeArticleFetcher`, a public service, so the test reads it from `static::getContainer()`.

No new Composer package. `yumo/article-reader` is already a direct requirement and carries the library.

## Data flow

`POST /api/archives` with `{"url":"https://example.com/a"}`:

1. The resolver maps and validates the body, as before.
2. `ArchiveServiceResolver` asks `OwnerProvider` for the `Owner` and `ArchiveServiceFactory` for the service of that owner, and passes it to the action (revised by slice 5b).
3. `ArchiveService::archive` finds nothing, fetches through `ArticleFetcher`, builds and saves the `Archive`, and returns `Written`.
4. The controller answers 201 with `{"url":"https://example.com/a","title":"..."}`.

A second POST of the same string finds the archive on the shelf, does not fetch, and answers 200 with the same JSON.

## Errors

Every failure after the body map is an `ArchiveException` from the service. The controller does not catch it. Symfony's own error response is the answer until the problem-document slice. The tests do not assert its status or its text. They assert only that the body is not the success JSON and the content type is not `application/problem+json`.

A failed fetch stores nothing, so the next POST of that URL fetches again. This slice has no negative cache.

`""`, `" "`, and `not-a-url` pass the body map. In production the library's `UrlGuard` rejects them inside the orchestrator, and the adapter lets that `OrchestratorException` reach the service, which wraps it. In the tests the fake rejects them the same way.

The 400, 415, and 405 refusals stay as the payload slice left them.

## Test

Support class `Yumo\Eleanor\Tests\Support\FakeArticleFetcher` in `tests/Support/`:

- `willReturn(string $url, PipelineSuccess|PipelineNoContent $outcome)`.
- `willThrow(string $url, \Throwable $failure)`.
- `fetchCount(?string $url = null): int`.
- A URL with no registered outcome throws `OrchestratorException(OrchestratorError::UrlGuard)`, as the real guard would for a string that is not a usable URL.

A small builder in `tests/Support/` makes a `PipelineSuccess` from a title, an optional excerpt and site name, and HTML, with optional warnings.

Unit tests, no kernel, in `tests/Archive/` and `tests/Api/Owner/`:

- `OwnerTest`: keeps the email.
- `ConfiguredOwnerProviderTest`: `owner()` returns an `Owner` with the configured email.
- `InMemoryArchiveStoreTest`: an unknown URL is absent. `save` then `find` returns the same archive. A store for another owner from the same shelf does not see it. A second store for the same owner does.
- `InMemoryArchiveStoreFactoryTest`: two `create` calls for the same owner share rows, and another owner does not.
- `ArchiveServiceFactoryTest`: with the real in-memory factory and the fake, the second service for the same owner returns `AlreadyStored` for what the first wrote.
- `ArchiveServiceTest`: the cases listed in the contract's Testing Decisions. In addition, `url` is the string passed in, not the document's `sourceUrl`, and a failed fetch leaves the next call free to fetch again.

HTTP test: `CreateArchiveRouteTest` stays the one automated seam, and calls `$client->disableReboot()` so the shelf survives between two requests. It reads the fake and its `fetchCount`. Cases:

- A new URL: route `create_archive`, 201, `application/json`, `{"url":...,"title":...}` with `url` first, no email.
- The same URL again: 200, the same body, one fetch.
- A different URL: 201, a second fetch.
- A title with a quote and a backslash decodes to the same title.
- An empty title (`PlainText` of `""`) gives `"title":""`.
- `""`, `" "`, `not-a-url`, a `PipelineNoContent`, and a thrown failure: not the success JSON, not `application/problem+json`.
- A query string, `Accept: text/html`, an extra property, and a charset on the content type: still the archive.
- The 400, 415, GET, HEAD, and kernel boot cases stay.

`LogApiRequestTest` also posts `https://example.test/a` and asserts 200 and `{"ok":true}` in `test_post_stores_the_sent_id_agent_and_referer`. The `postArchives` helper of that file registers an outcome for that URL in the fake, so every test in the file reaches a success. That test then asserts 201 and the success JSON. It stays a logging test.

`packages/web-app/README.md`: the curl shows the 201 body of `url` and `title`, and says where the owner email is set. The curl is a manual check. It is not a second automated seam.

`./bin/quality` passing is an acceptance check, not a test.

## Out of scope

The contract's Out of Scope list stands. Specifically for this module: a store that survives a process, a negative cache, URL normalization, a log line for an archive, the authenticated `OwnerProvider`, and any change to the library.

`ArticleReaderAdapter` has no test of its own. The library's tests prove the orchestrator, and the adapter has one line.
