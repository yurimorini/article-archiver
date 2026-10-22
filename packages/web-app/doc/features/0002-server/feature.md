# Feature: Eleanor HTTP server

## Goal

Eleanor is a Symfony application. It exposes a local HTTP API. The finished command is `POST /api/archives`. The body carries a URL. The HTTP layer calls one archive service. That service runs the ArticleReader orchestrator and stores the archive for the owner. The route returns the outcome of that command.

One owner is configured for this feature. The email identifies the owner and is a Symfony parameter from the archive-command slice. A bearer token in configuration authenticates calls as that owner. The firewall and the token come after that slice. Until the firewall slice, the route answers without a token. Human login is backlog.

The server is not open to the public internet. The runtime slice serves it with PHP-FPM behind a Docker reverse proxy. Earlier slices use a temporary server.

A URL pointer and the archive stay distinct. Clients (browser, script, phone) are interchangeable against the JSON API.

The slice specs adopt the research for the contract, or they replace it. The target that research describes is: the success JSON is `url` and `title`; a write is 201; an archive this owner already has is 200; failures use 400, 422, 502, and 500; one SQLite file holds that owner's archives. Logging uses separate channels. The API schema is produced from the code and stays current with it.

Terms live in the package [GLOSSARY.md](../../GLOSSARY.md). **Owner** is the person an archive belongs to, identified by an email address.

## How to continue

This file is the record a new session reads first. The research queue is closed. `research/Open.md` points here.

Work the slices in table order. The category is a label. It is not a build order.

One slice at a time:

1. Set that row to `In progress` before changing application code.
2. Write its spec in `specs/` and its plan in `plans/YYYY-MM-DD-<name>.md`. The spec adopts the research that slice needs, or writes the replacement in the spec. Proposed ADRs stay proposed until the spec adopts them.
3. Implement that slice. The plan names the unit tests, the end-to-end check, and any debug or validation aid the slice needs.
4. Set the row to `Done`, and link the spec and the plan, when those tests pass and `./bin/quality` passes for `web-app`.

A slice may be added, split, or merged. Record the change in this file before the next slice starts. A sub-feature directory is added when one slice grows its own research and two sessions would write the same `specs/` folder. Pull a backlog item into a slice only by recording that change here. Otherwise it stays deferred and can be reprioritized later.

The HTTP layer sees one archive service. The orchestrator and storage are inside that service. The service is `ArchiveService`. The storage boundary is `ArchiveStore`. The factory is `ArchiveServiceFactory`.

## Slices

| Order | Slice                                                        | Category          | Done when                                                                                                                                                                                                                                                  | Status                                                                        |
| ----- | ------------------------------------------------------------ | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| 1     | [Symfony skeleton](symfony-skeleton.md)                      | Shell             | The PHP namespace is chosen, the app boots, and quality checks pass                                                                                                                                                                                        | Done                                                                          |
| 2     | [Empty route](empty-route.md)                                | Shell             | `POST /api/archives` returns 200 `{"ok":true}` as `application/json` for any body. A WebTestCase asserts the route, the status, the content type, and the body. The web-app README starts the temporary server                                             | Done                                                                          |
| 3     | Logging                                                      | Shell             | A request writes a line on the configured channels                                                                                                                                                                                                         | Done                                                                          |
| 4     | [Payload and mapper](payload-and-mapper.md)                  | Archive route     | A body is accepted or refused before the service runs                                                                                                                                                                                                      | Done                                                                          |
| 5     | [Archive command](archive-command.md)                        | Archive route     | `POST /api/archives` uses the configured owner email, archives the URL through `ArchiveService` and an in-memory `ArchiveStore`, and returns 201 or 200 with `url` and `title`. A second POST of the same URL returns 200 and does not fetch               | Done                                                                          |
| 5b    | [Owner-scoped service resolver](specs/OwnerScopedService.md) | Archive route     | A value resolver builds the owner's `ArchiveService`. `CreateArchiveController` has no constructor and receives the service as an action argument. Status, body, and logs do not change. All existing tests pass and `./bin/quality` passes for `web-app`  | Done ([plan](plans/2026-10-18-owner-scoped-service.md))                       |
| 6     | [Error response](error-response.md)                          | Archive route     | Every failure under `/api` answers `application/json` with `code` and `message` (400, 404, 405, 415, 422, 502, 500). Eleanor's serializer writes the success and the error JSON. OpenAPI was removed on 2026-10-18                                  | Done ([plan](plans/2026-10-18-error-response.md))                             |
| 7     | [SQLite store](sqlite-store.md)                              | Archive execution | The in-memory store is replaced by the owner's SQLite file. A second POST in a new process returns the stored row. Two overlapping POSTs of one URL take turns through an archive lock: one 201, one 200, one fetch                                        | Done ([plan](plans/2026-10-18-sqlite-store.md), [spec](specs/SqliteStore.md)) |
| 8     | [Bearer](bearer.md)                                          | Archive route     | A matched `/api` route requires `Authorization: Bearer` with `ELEANOR_BEARER_TOKEN`. Without a valid secret the answer is 401 `unauthenticated`. A missing or empty variable is 500 `unexpected`. The matching secret authenticates the Owner of the configured email | Done                                                                          |
| 9     | Runtime                                                      | Runtime           | PHP-FPM behind the Docker reverse proxy serves the app                                                                                                                                                                                                     | Not started                                                                   |

Slices 1–7 answer without a token. Slice 8 is the firewall and the configured bearer together: it refuses an anonymous call and accepts the secret. The old token row is absorbed here. The owner email parameter arrives in slice 5. The runtime row moves from 10 to 9.

## Slice merge (2026-10-17)

Slice 5 absorbs the old application-service, ArticleReader, and storage-interface slices, the in-memory stand-in for the SQLite slice, and the success half of the old response slice. SQLite, the problem document, the serializer, the schema, the firewall, the token, and the runtime stay in the rows above. The spec of slice 5 adopts the replacements below. Proposed ADRs stay proposed until that spec adopts them.

- `ArchiveService` is constructed with one `ArchiveStore` and one `ArticleFetcher`. It does not hold the `Owner`; the store is already that owner's (revised 2026-10-18). `ArticleFetcher` is a web-app interface. `ArticleReaderAdapter` implements it over the library `Orchestrator`, which is `final` and cannot be doubled (revised 2026-10-18). `archive` receives a `UrlPointer` (revised by [UrlPointer.md](specs/UrlPointer.md); before that revision it received the URL string and trimmed it). The owner is not an argument. The controller asks an `OwnerProvider` for the `Owner` and calls `ArchiveServiceFactory::create`. Slice 5b moved those two calls into `ArchiveServiceResolver`, so every later controller declares `ArchiveService` as an argument instead of repeating them ([spec](specs/OwnerScopedService.md)). This slice's `ConfiguredOwnerProvider` builds the `Owner` from the Symfony email parameter. The firewall slice replaces the provider with one that reads the authenticated caller (revised 2026-10-18). A CLI does the same. The service does not read the parameter.
- `ArchiveStore` is bound to that owner. `find` and `save` do not take the owner. A later adapter opens that owner's SQLite file. The service does not. `ArchiveStoreFactory::create(Owner)` builds each store. This slice's in-memory factory holds no state and shares one `InMemoryArchiveShelf` between stores. `ArchiveServiceFactory` holds no state.
- `Archive` is the domain copy of the fetched document: `url`, `title`, `excerpt`, `siteName`, and `html`. Text fields are plain strings. `excerpt` and `siteName` may be null. `ArchiveResult` carries the `Archive` and an `ArchiveOutcome` of `Written` (201) or `AlreadyStored` (200). The JSON body is `url` and `title`, written with `JsonResponse::fromJsonString`. A second POST of the same URL is `AlreadyStored` and does not fetch.
- A library failure, including no article, becomes `ArchiveException`. The original exception remains the cause. The controller does not catch it.

Slice 6 owns the error response, the statuses 400, 404, 405, 415, 422, 502, and 500, and Eleanor's serializer. Slice 7 owns the SQLite file. Slice 8 owns the bearer. The email parameter already exists.

## Slice 6 revision (2026-10-18)

The slice 6 contract is [error-response.md](error-response.md). It changes four things from the research notes and the first wording of the row.

- The row is renamed from "Problem document" to "Error response". The error body is `application/json` with `code` and `message`. It replaces the `application/problem+json` object with `status`, `cause`, `title`, and `detail` in [research/Types.md](research/Types.md) and [research/Schema.md](research/Schema.md). The decision was made from the client's side: the status is the family, `code` is the cause, `message` is the sentence a person reads, and the request id stays in the `X-Request-Id` header.
- The success body stays flat (`url`, `title`). There is no envelope.
- 404, 405, and 415 join the translated errors, so every failure under `/api` has one shape.
- The schema is an OpenAPI document generated by `zircote/swagger-php`, a development dependency, committed and checked by a drift test. A runtime validator is not added. Removed on 2026-10-18; see below.

## OpenAPI removal (2026-10-18)

Eleanor does not publish an OpenAPI document. The attributes on the controller repeated the status and `code` matrix, and a scan of the controller cannot see that matrix. Kernel tests prove each status and each `code`. No schema package remains.

## Slice 7 revision (2026-10-18)

The slice 7 contract is [sqlite-store.md](sqlite-store.md). The grill did not add a slice. It changes slice 5 in four places, and the module spec of slice 7 writes those changes into [ArchiveCommand.md](specs/ArchiveCommand.md).

- The archive lock is part of slice 7. It serializes the lookup, the fetch, and the save for one owner and URL, so two overlapping POSTs of one URL fetch once. It is not a separate slice because it can only work once the store is a file shared between PHP processes.
- `ArchiveStore` gains `findSummary(url): ?ArchiveSummary`. The service uses it for the "already stored" check, so the second POST reads no HTML. `find` stays for a later endpoint that returns the whole article. `ArchiveResult` carries an `ArchiveSummary`.
- `save` is an upsert. It replaces the idea in [research/Storage.md](research/Storage.md) that a second insert hits the unique URL and reads the stored row.
- The error-response slice reserved `archive_not_written`. Slice 7 adds it: a failed write is 500 with that code.

## URL pointer (2026-10-18)

Not a new slice. [UrlPointer.md](specs/UrlPointer.md) moves the leading-and-trailing NUL trim out of `ArchiveService` and into `UrlPointer`. The lock, the fetch, and the store take that type. `toString()` is the string that reaches SQL, the flock key, and the library. The body DTO stays a string. The controller builds the pointer. [SqliteStore.md](specs/SqliteStore.md) and [ArchiveCommand.md](specs/ArchiveCommand.md) record the revised signatures. No ADR changes. Plan: [url pointer](plans/2026-10-18-url-pointer.md).

## Archive log (2026-10-19)

Not a new slice. The module design is [ArchiveLog.md](specs/ArchiveLog.md). Plan: [archive log](plans/2026-10-19-archive-log.md). `ArchiveService` is the first `domain` writer. `RequestIdProcessor` copies the stored request id onto `extra.request_id` so those lines join the HTTP round. A save line carries the SQLite path from `ArchiveStore::location()`. [Logging.md](specs/Logging.md) keeps the HTTP lines and points here.

## Store save failure (2026-10-22)

Not a new slice, and not the current ticket. `SqliteArchiveStore::save` throws `ArchiveException` with `NotWritten` when the directory cannot be created or the database fails. The cause stays `DirectoryException` or `\PDOException`. The shared connection helper does not wrap, so `findSummary` and `find` still let those two through. `ArchiveService` logs `archive.failed` and rethrows that exception. It no longer catches the SQLite exceptions. [ArchiveCommand.md](specs/ArchiveCommand.md), [SqliteStore.md](specs/SqliteStore.md), and [ArchiveLog.md](specs/ArchiveLog.md) record this. The [store composition](#store-composition-2026-10-18) note below is the change that put the catch in the service. This revision moves that catch into the adapter. Plan: [store save failure](plans/2026-10-22-store-save-failure.md). No ADR changes. ADR 0008 stays closed.

## Error reply (2026-10-22)

Not a new slice, and not the current ticket. `ArchiveFailurePresenter` was one adapter on a seam nothing else implements. The status and message tables live in `ErrorTranslator`, the module that turns a throwable into a `Reply`. `TranslateApiException` stays the HTTP adapter. [SqliteStore.md](specs/SqliteStore.md) names `ErrorTranslator` for the `NotWritten` row. Plan: [error reply](plans/2026-10-22-error-reply.md). ADR 0001 stays. The four status classes do not move.

## Fetch seam (2026-10-22)

Not a new slice. The contract is [fetch-seam.md](fetch-seam.md). The ticket is [02 — The fetch seam returns an Article](../../issues/02-fetch-seam-returns-an-article.md). [ArchiveCommand.md](specs/ArchiveCommand.md) deepens the fetch seam. `ArticleFetcher` returns an `Article` and throws `ArchiveException`. `ArticleReaderAdapter` maps the library outcome through `ArchiveFailureMapper`. `ArchiveService` no longer names a reader type and no longer takes the mapper. The glossary term Article is that purified body, and it has no URL. The alternative was to delete the seam and let the command call `Orchestrator`. [adr/0009](../../adr/0009-fetch-seam-returns-an-article.md) records that choice. Plan: [fetch seam](plans/2026-10-22-fetch-seam.md).

## Store composition (2026-10-18)

Not a new slice. `SqliteArchiveStore` no longer builds its connection or takes a path. `SqliteArchiveStoreFactory` holds one `SqliteSchema` and passes `new SqliteConnection($path, $schema)` into the store. `SqliteConnection::open` creates a missing parent directory at mode `0700` and raises `DirectoryException` when it cannot. A database failure stays a `\PDOException`. `ArchiveService` catches the two exceptions apart and maps each to `archive_not_written`. [SqliteStore.md](specs/SqliteStore.md) recorded this. The catch location moves in [Store save failure](#store-save-failure-2026-10-22).

## Bearer (2026-10-22)

The firewall row and the token row are one slice, now row 8. The runtime row is 9. The contract is [bearer.md](bearer.md). The module design is [Bearer](specs/Bearer.md). Plan: [bearer](plans/2026-10-22-bearer.md). A matched route under `/api` requires the configured bearer. The secret is `ELEANOR_BEARER_TOKEN`. The email parameter stays the Owner. No ADR is added. The execution plan adds the README section that tells the Owner where the secret goes and what the 401 and the 500 look like. 403 stays out of this slice.

## Leftovers

Findings from the slice 5 review that the slice did not fix. Each names the slice that handles it. Delete a line when that slice does it.

None.

## Backlog

Deferred. No slice owns these until this file says one does. When a need shows up, decide then, or leave the item in the package [backlog](../../backlog/) and reprioritize it.

- [Idempotency-Key](../../backlog/idempotency-key.md)
- [Human login](../../backlog/human-login.md)
- [Second owner](../../backlog/second-owner.md)
- [HTML pages](../../backlog/html-pages.md)
- [CORS, HTTP caching, CSRF, and a version segment](../../backlog/cors-caching-csrf-version.md)
- [Limits and configuration](../../backlog/limits-and-configuration.md)
- [Refresh a changed page](../../backlog/refresh-changed-page.md)
- [Image bytes and later assets](../../backlog/image-bytes-and-later-assets.md)
- [Clean distribution image](../../backlog/clean-distribution-image.md)

## Research coverage

A file in `research/` is a direction until a slice spec adopts it. `research/Investigations.md` is an early list of ideas, not a queue. ADRs in `[adr/](../../adr/)` stay proposed until a spec adopts them.

| Topic               | Record                                                           | What it covers                                                                                         |
| ------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Login               | [research/Login.md](research/Login.md)                           | The configured token now, and human login later.                                                       |
| First route         | [research/Route.md](research/Route.md)                           | `POST /api/archives`.                                                                                  |
| Controller boundary | [research/Controller.md](research/Controller.md)                 | The HTTP adapter, the service it calls, and the orchestrator.                                          |
| Archive flow        | [research/Flow.md](research/Flow.md)                             | What the service does with one URL, and the status of each outcome.                                    |
| Types and payload   | [research/Types.md](research/Types.md)                           | Request body, success body, and the error document.                                                    |
| Schema              | [research/Schema.md](research/Schema.md)                         | What a schema declares, and which tools check or publish it.                                           |
| Logging             | [research/Logging.md](research/Logging.md)                       | Where a request fact goes, and how the log streams are written.                                        |
| Domain storage      | [research/Storage.md](research/Storage.md)                       | What one accepted archive writes, which owner it belongs to, and how two posts of one URL meet.        |
| SQLite              | [research/Sqlite.md](research/Sqlite.md)                         | What SQLite's own docs say about one file per owner, and about a later asset.                          |
| Symfony controllers | [research/Symfony-controller.md](research/Symfony-controller.md) | A reading of the Symfony controller docs. Not a decision.                                              |
| Research queue      | [research/Open.md](research/Open.md)                             | The closed session list. Implementation starts from this file.                                         |
| Early ideas         | [research/Investigations.md](research/Investigations.md)         | Ideas that are not a mandatory sequence.                                                               |
| HTTP tests          | [research/Testing.md](research/Testing.md)                       | What a WebTestCase, a real HTTP call, a manual check, and an agent browser each prove. Not a decision. |
| Owner-scoped service | [research/Owner-scoped-service.md](research/Owner-scoped-service.md) | How a controller can receive the owner-scoped service without building it. Not a decision.            |

| ADR                                                                                                    | Direction                                          |
| ------------------------------------------------------------------------------------------------------ | -------------------------------------------------- |
| [adr/0001-archive-outcome-status.md](../../adr/0001-archive-outcome-status.md)                         | Failures use 400, 422, 502, and 500.               |
| [adr/0002-second-post-returns-stored-archive.md](../../adr/0002-second-post-returns-stored-archive.md) | A second POST of the same URL is 200.              |
| [adr/0003-owner-database-is-wal.md](../../adr/0003-owner-database-is-wal.md)                           | WAL, page size 8192, and a checkpointed export. Accepted by [SQLite store](specs/SqliteStore.md). |
| [adr/0004-archive-lookup-is-per-owner.md](../../adr/0004-archive-lookup-is-per-owner.md)               | Lookup is scoped to one owner.                     |
| [adr/0005-api-routes-are-a-stateless-import.md](../../adr/0005-api-routes-are-a-stateless-import.md)   | JSON routes sit under one stateless `/api` import. |
| [adr/0006-kernel-tests-live-in-integration.md](../../adr/0006-kernel-tests-live-in-integration.md)     | A PHPUnit test that boots the kernel lives in `tests/Integration/`. |
| [adr/0007-archive-body-mapped-by-map-request-payload.md](../../adr/0007-archive-body-mapped-by-map-request-payload.md) | `MapRequestPayload` maps the body. A bad media type is 415. Accepted by [Payload and mapper](specs/PayloadAndMapper.md). |
| [adr/0008-owner-database-schema-splits-body-from-metadata.md](../../adr/0008-owner-database-schema-splits-body-from-metadata.md) | Three tables in v1. Search is a later migration. Accepted by [SQLite store](specs/SqliteStore.md). |
| [adr/0009-fetch-seam-returns-an-article.md](../../adr/0009-fetch-seam-returns-an-article.md) | The fetch port returns an Article. The command does not name the reader. Accepted by [Archive command](specs/ArchiveCommand.md). |

## Current status

| Area             | State                                                                                                                                                                                     |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Discovery        | Recorded. The queue in [research/Open.md](research/Open.md) is closed.                                                                                                                    |
| Next slice       | 8 — [Bearer](bearer.md). Slice 7 [SQLite store](sqlite-store.md) is Done. Slice 6 [Error response](error-response.md) is Done. Slice 5 [Archive command](archive-command.md) is Done. |
| Contract         | [bearer.md](bearer.md) is the bearer slice, not started. [sqlite-store.md](sqlite-store.md), closed as the brief of [SqliteStore.md](specs/SqliteStore.md). Slice 7 is Done. Slice 6 remains [error-response.md](error-response.md). Slice 5 remains [archive-command.md](archive-command.md). The fetch-seam revision is [fetch-seam.md](fetch-seam.md), not a slice. Slice 4 remains [payload-and-mapper.md](payload-and-mapper.md). Slice 3 remains [logging.md](logging.md). Slice 2 remains [empty-route.md](empty-route.md). Slice 1 remains [symfony-skeleton.md](symfony-skeleton.md). |
| Current ticket   | [02 — The fetch seam returns an Article](../../issues/02-fetch-seam-returns-an-article.md). [01 — Boot Eleanor's kernel in the test environment](../../issues/01-boot-eleanor-kernel.md) is Done. |
| Namespace        | `Yumo\Eleanor` on `src/`. `Yumo\Eleanor\Tests` on `tests/`. The boot test namespace is `Yumo\Eleanor\Tests\Integration`, per [adr/0006](../../adr/0006-kernel-tests-live-in-integration.md). |
| Application code | `Yumo\Eleanor\Kernel` boots. `POST /api/archives` is `create_archive` in `Yumo\Eleanor\Api\Controller\CreateArchiveController`. It has no constructor and receives the owner's `ArchiveService` as an action argument, built by `Yumo\Eleanor\Api\Resolver\ArchiveServiceResolver` from `OwnerProvider` and `ArchiveServiceFactory`. It archives the body's URL, and answers 201 or 200 with `url` and `title`. The archive command lives in `src/Archive/`. The SQLite store lives in `src/Archive/Sqlite/` (`SqliteArchiveStore`, `SqliteArchiveStoreFactory`, `SqliteConnection`, `SqliteSchema`). The per-URL archive lock lives in `src/Archive/Lock/` (`FlockArchiveLock`, `FlockArchiveLockFactory`). The in-memory store is test support only, under `tests/Support/InMemory/`. `LogApiRequest` writes `http.request.start` and `http.request.finish` on `application`. Slice 6 added the error response. `Archive\ArchiveFailure` (a string enum of wire codes) and `Archive\ArchiveFailureMapper` turn library failures into `ArchiveException`. `Api\Response\{ArchiveResponse, ErrorResponse, Reply, JsonBodySerializer, ReplyResponder}` write every body. The controller returns a `Reply`. `Api\Error\{RequestFailure, ErrorTranslator}` map a throwable to a `Reply`. `EventSubscriber\WriteReply` (`kernel.view`) and `EventSubscriber\TranslateApiException` (`kernel.exception`, priority 10) hand a `Reply` to `ReplyResponder`. Tests: `tests/Archive/`, `tests/Api/Error/`, `tests/Api/Response/`, `tests/Api/Owner/`, `tests/Api/Resolver/`, `tests/EventSubscriber/`, `tests/Integration/CreateArchiveRouteTest.php`, `tests/Integration/ErrorResponseRouteTest.php`. |
| Specs and plans  | [Kernel](specs/Kernel.md). [Empty route](specs/EmptyRoute.md). [Payload and mapper](specs/PayloadAndMapper.md) adopts [adr/0007](../../adr/0007-archive-body-mapped-by-map-request-payload.md). [Archive command](specs/ArchiveCommand.md) adopts [adr/0002](../../adr/0002-second-post-returns-stored-archive.md), [adr/0004](../../adr/0004-archive-lookup-is-per-owner.md), and [adr/0009](../../adr/0009-fetch-seam-returns-an-article.md), Plan: [archive command](plans/2026-10-18-archive-command.md). [Owner-scoped service](specs/OwnerScopedService.md) (slice 5b), plan: [owner-scoped service](plans/2026-10-18-owner-scoped-service.md). [Error response](error-response.md) (slice 6), plan: [error response](plans/2026-10-18-error-response.md). No module spec was written for slice 6. [SQLite store](specs/SqliteStore.md) (slice 7) adopts [adr/0003](../../adr/0003-owner-database-is-wal.md) and [adr/0008](../../adr/0008-owner-database-schema-splits-body-from-metadata.md) and revises [Archive command](specs/ArchiveCommand.md). Plan: [sqlite store](plans/2026-10-18-sqlite-store.md). Slice 7 is Done. [URL pointer](specs/UrlPointer.md) revises the URL argument of that command. It is not a slice. Plan: [url pointer](plans/2026-10-18-url-pointer.md). [Archive log](specs/ArchiveLog.md) is the first `domain` writer. It is not a slice. Plan: [archive log](plans/2026-10-19-archive-log.md). The three items the contract left open (`invalid_json` vs `invalid_body`, the OpenAPI version and location, the upstream status number) are closed in the plan's Decisions. OpenAPI itself was removed on 2026-10-18. Plans: [kernel](plans/2026-10-10-kernel.md), [empty route](plans/2026-10-12-empty-route.md), [logging](plans/2026-10-13-logging.md), [payload and mapper](plans/2026-10-13-payload-and-mapper.md). The test-run bootstrap is the Symfony recipe’s `tests/bootstrap.php`. The rest of the library PHPUnit configuration stays. |


