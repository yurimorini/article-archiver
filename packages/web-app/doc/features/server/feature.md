# Feature: Eleanor HTTP server

## Goal

Eleanor is a Symfony application. It exposes a local HTTP API and, later, HTML pages. The first command accepts a URL, runs the ArticleReader orchestrator, and stores the article on disk the operator controls.

People who use Eleanor are known (one operator, or a family) and may be signed in at the same time. The server is not open to the public internet.

A URL pointer and the archived copy of a page stay distinct. Clients (browser, script, phone) are interchangeable against the JSON API.

## Documents

| Document | Role |
| --- | --- |
| [research/Login.md](research/Login.md) | How someone gets in. Decided direction and the options that were compared. |
| [research/Route.md](research/Route.md) | Contract of the first route: `POST /api/archives`. |
| [research/Controller.md](research/Controller.md) | HTTP adapter, application service, and orchestrator for that route. |
| [research/Flow.md](research/Flow.md) | What the service does with one URL, and the status of each outcome. |
| [research/Types.md](research/Types.md) | Request body, success body, error document, and the idempotency-key record. Not final. |
| [research/Schema.md](research/Schema.md) | What a schema would declare for those payloads, and which tools check or publish it. Not final. |
| [research/Logging.md](research/Logging.md) | Where a request fact goes, and how the three log streams are written. Not final. |
| [research/Sqlite.md](research/Sqlite.md) | What SQLite's own docs say about one file per owner, and about a later asset. Not the storage session. Not final. |
| [research/Storage.md](research/Storage.md) | What one accepted archive writes, which owner it belongs to, and how two posts of one URL meet. Not final. |
| [adr/0001-archive-outcome-status.md](adr/0001-archive-outcome-status.md) | Research direction for why failures use 400, 422, 502, and 500. Not final. |
| [adr/0002-second-post-returns-stored-archive.md](adr/0002-second-post-returns-stored-archive.md) | Research direction for why a second POST of the same URL is 200. Not final. |
| [adr/0003-owner-database-is-wal.md](adr/0003-owner-database-is-wal.md) | Research direction for WAL, page size 8192, and a checkpointed export. Not final. |
| [adr/0004-archive-lookup-is-per-owner.md](adr/0004-archive-lookup-is-per-owner.md) | Research direction for an owner-scoped lookup. Not final. |
| [research/Symfony-controller.md](research/Symfony-controller.md) | Reading of the Symfony controller docs. Not a decision. |
| [research/Open.md](research/Open.md) | Remaining design sessions, in order. Start here in a fresh session. |
| [research/Investigations.md](research/Investigations.md) | Early generic ideas. Not a mandatory sequence. |

Research notes live in `research/`. A note there is a direction until a spec adopts it. Specs and plans are added when a design is adopted. ADRs for this feature live in `adr/`. During research an ADR stays proposed.

## Research directions

These rows record the current research. They are not final decisions. A spec adopts one when implementation starts. A later session can replace one.

| Topic | Direction |
| --- | --- |
| Framework | Symfony. The bootstrap feature left this unset; this feature chooses it. |
| First access implementation | A token in configuration, checked on each request. This exists so the application and the check can be built before human login. |
| Human login | Later. An allowlisted email is proved once, or again every several weeks or months, and the device then holds a passkey. An external identity provider is documented in the login discovery and is not the preferred path. |
| First route | `POST /api/archives`. Detail in [research/Route.md](research/Route.md). |
| Controller boundary | One invokable class per route calls a concrete application service. The service calls the orchestrator. Detail in [research/Controller.md](research/Controller.md). |
| Archive flow | The POST stores the archive inside the request. A write is 201. An archive already stored is 200. Failures use 400, 422, 502, and 500. Detail in [research/Flow.md](research/Flow.md). |
| Types and payload | The body is `{"url":"..."}`. The success JSON is `url` and `title`. Errors share one document with a `cause` token. Detail in [research/Types.md](research/Types.md). |
| Schema checks | The request DTO is checked on the `#[MapRequestPayload]` path, before the service. The success body and the problem document are a schema for readers and a contract check beside the request. Detail in [research/Schema.md](research/Schema.md). |
| Request facts | A fact stays on the request until something decides with it. `application` and `domain` share one JSON stream, on stderr for now. A second config rotates one file and keeps seven. The owner's email is masked on `domain` after the owner is resolved. Detail in [research/Logging.md](research/Logging.md). |
| Domain storage | One SQLite file per owner, WAL, page size 8192. The row holds the URL, the title, the excerpt, the site name, and the purified HTML. Two posts that both miss the row may both fetch; the first insert wins and the other returns 200. Detail in [research/Storage.md](research/Storage.md). |

## Current status

| Area | State |
| --- | --- |
| Discovery | Login, the first route, the controller boundary, the archive flow, the types, the log line, schema checks, and domain storage are recorded. The remaining sessions are listed in [research/Open.md](research/Open.md). `research/Investigations.md` holds ideas that are not yet adopted. |
| Namespace | Unset, as in the bootstrap feature. |
| Application code | `src/` and `tests/` are empty. |
