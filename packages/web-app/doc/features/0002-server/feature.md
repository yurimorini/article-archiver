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


| Order | Slice                                   | Category          | Done when                                                                                                                                                                                                      | Status      |
| ----- | --------------------------------------- | ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| 1     | [Symfony skeleton](symfony-skeleton.md) | Shell             | The PHP namespace is chosen, the app boots, and quality checks pass                                                                                                                                            | Done        |
| 2     | [Empty route](empty-route.md)           | Shell             | `POST /api/archives` returns 200 `{"ok":true}` as `application/json` for any body. A WebTestCase asserts the route, the status, the content type, and the body. The web-app README starts the temporary server | Done        |
| 3     | Logging                                 | Shell             | A request writes a line on the configured channels                                                                                                                                                             | Done        |
| 4     | [Payload and mapper](payload-and-mapper.md) | Archive route     | A body is accepted or refused before the service runs                                                                                                                                                          | Done        |
| 5     | [Archive command](archive-command.md)   | Archive route     | `POST /api/archives` uses the configured owner email, archives the URL through `ArchiveService` and an in-memory `ArchiveStore`, and returns 201 or 200 with `url` and `title`. A second POST of the same URL returns 200 and does not fetch | Not started |
| 6     | Problem document                        | Archive route     | Failures share one problem document (400, 422, 502, 500). Eleanor's serializer writes the JSON. The schema is generated from the code                                                                           | Not started |
| 7     | SQLite store                            | Archive execution | The in-memory store is replaced by the owner's SQLite file. A second POST in a new process returns the stored row                                                                                               | Not started |
| 8     | Firewall                                | Archive route     | The archive route requires an authenticated caller                                                                                                                                                              | Not started |
| 9     | Token                                   | Archive route     | The configured bearer token authenticates the owner identified by the configured email                                                                                                                          | Not started |
| 10    | Runtime                                 | Runtime           | PHP-FPM behind the Docker reverse proxy serves the app                                                                                                                                                          | Not started |


Slices 1–7 answer without a token. Slice 8 is the increment that starts refusing an anonymous call. Slice 9 adds the configured token. The owner email parameter arrives in slice 5.

## Slice merge (2026-10-17)

Slice 5 absorbs the old application-service, ArticleReader, and storage-interface slices, the in-memory stand-in for the SQLite slice, and the success half of the old response slice. SQLite, the problem document, the serializer, the schema, the firewall, the token, and the runtime stay in the rows above. The spec of slice 5 adopts the replacements below. Proposed ADRs stay proposed until that spec adopts them.

- `ArchiveService` is constructed with one `ArchiveStore` and one `ArticleFetcher`. It does not hold the `Owner`; the store is already that owner's (revised 2026-10-18). `ArticleFetcher` is a web-app interface. `ArticleReaderAdapter` implements it over the library `Orchestrator`, which is `final` and cannot be doubled (revised 2026-10-18). `archive` receives the URL string. The owner is not an argument. The controller asks an `OwnerProvider` for the `Owner` and calls `ArchiveServiceFactory::create`. This slice's `ConfiguredOwnerProvider` builds the `Owner` from the Symfony email parameter. The firewall slice replaces the provider with one that reads the authenticated caller (revised 2026-10-18). A CLI does the same. The service does not read the parameter.
- `ArchiveStore` is bound to that owner. `find` and `save` do not take the owner. A later adapter opens that owner's SQLite file. The service does not. `ArchiveStoreFactory::create(Owner)` builds each store. This slice's in-memory factory holds no state and shares one `InMemoryArchiveShelf` between stores. `ArchiveServiceFactory` holds no state.
- `Archive` is the domain copy of the fetched document: `url`, `title`, `excerpt`, `siteName`, and `html`. Text fields are plain strings. `excerpt` and `siteName` may be null. `ArchiveResult` carries the `Archive` and an `ArchiveOutcome` of `Written` (201) or `AlreadyStored` (200). The JSON body is `url` and `title`, written with `JsonResponse::fromJsonString`. A second POST of the same URL is `AlreadyStored` and does not fetch.
- A library failure, including no article, becomes `ArchiveException`. The original exception remains the cause. The controller does not catch it.

Slice 6 owns the problem document, the statuses 400, 422, 502, and 500, Eleanor's serializer, and the schema. Slice 7 owns the SQLite file. Slice 9 owns the bearer token. The email parameter already exists.

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



| ADR                                                                                                    | Direction                                          |
| ------------------------------------------------------------------------------------------------------ | -------------------------------------------------- |
| [adr/0001-archive-outcome-status.md](../../adr/0001-archive-outcome-status.md)                         | Failures use 400, 422, 502, and 500.               |
| [adr/0002-second-post-returns-stored-archive.md](../../adr/0002-second-post-returns-stored-archive.md) | A second POST of the same URL is 200.              |
| [adr/0003-owner-database-is-wal.md](../../adr/0003-owner-database-is-wal.md)                           | WAL, page size 8192, and a checkpointed export.    |
| [adr/0004-archive-lookup-is-per-owner.md](../../adr/0004-archive-lookup-is-per-owner.md)               | Lookup is scoped to one owner.                     |
| [adr/0005-api-routes-are-a-stateless-import.md](../../adr/0005-api-routes-are-a-stateless-import.md)   | JSON routes sit under one stateless `/api` import. |
| [adr/0006-kernel-tests-live-in-integration.md](../../adr/0006-kernel-tests-live-in-integration.md)     | A PHPUnit test that boots the kernel lives in `tests/Integration/`. |
| [adr/0007-archive-body-mapped-by-map-request-payload.md](../../adr/0007-archive-body-mapped-by-map-request-payload.md) | `MapRequestPayload` maps the body. A bad media type is 415. Accepted by [Payload and mapper](specs/PayloadAndMapper.md). |
| [adr/0008-owner-database-schema-splits-body-from-metadata.md](../../adr/0008-owner-database-schema-splits-body-from-metadata.md) | Three tables in v1. Search is a later migration. |

## Current status


| Area             | State                                                                                                                                                                                     |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Discovery        | Recorded. The queue in [research/Open.md](research/Open.md) is closed.                                                                                                                    |
| Next slice       | 5 — [Archive command](archive-command.md). Not started. Status `ready-for-agent`. The merge is recorded under Slice merge (2026-10-17).                                        |
| Contract         | [archive-command.md](archive-command.md). Status `ready-for-agent`. Slice 4 remains [payload-and-mapper.md](payload-and-mapper.md). Slice 3 remains [logging.md](logging.md). Slice 2 remains [empty-route.md](empty-route.md). Slice 1 remains [symfony-skeleton.md](symfony-skeleton.md). |
| Current ticket   | [01 — Boot Eleanor's kernel in the test environment](../../issues/01-boot-eleanor-kernel.md). Slice 1 is Done. |
| Namespace        | `Yumo\Eleanor` on `src/`. `Yumo\Eleanor\Tests` on `tests/`. The boot test namespace is `Yumo\Eleanor\Tests\Integration`, per [adr/0006](../../adr/0006-kernel-tests-live-in-integration.md). |
| Application code | `Yumo\Eleanor\Kernel` boots. `POST /api/archives` is `create_archive`. The controller is `Yumo\Eleanor\Api\Controller\CreateArchiveController` in `src/Api/Controller/`. The body type is `Yumo\Eleanor\Api\Dto\CreateArchiveBody`. The action maps that body and returns the empty-route stub. `LogApiRequest` writes `http.request.start` and `http.request.finish` on `application`. The boot test is `tests/Integration/KernelTest.php`. |
| Specs and plans  | [Kernel](specs/Kernel.md). [Empty route](specs/EmptyRoute.md). [Payload and mapper](specs/PayloadAndMapper.md) adopts [adr/0007](../../adr/0007-archive-body-mapped-by-map-request-payload.md). [Archive command](specs/ArchiveCommand.md) adopts [adr/0002](../../adr/0002-second-post-returns-stored-archive.md) and [adr/0004](../../adr/0004-archive-lookup-is-per-owner.md), awaiting review. Plans: [kernel](plans/2026-10-10-kernel.md), [empty route](plans/2026-10-12-empty-route.md), [logging](plans/2026-10-13-logging.md), [payload and mapper](plans/2026-10-13-payload-and-mapper.md). The test-run bootstrap is the Symfony recipe’s `tests/bootstrap.php`. The rest of the library PHPUnit configuration stays. |


