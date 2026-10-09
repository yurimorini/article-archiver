# Feature: Eleanor HTTP server

## Goal

Eleanor is a Symfony application. It exposes a local HTTP API. The finished command is `POST /api/archives`. The body carries a URL. The HTTP layer calls one archive service. That service runs the ArticleReader orchestrator and stores the archive for the owner. The route returns the outcome of that command.

One owner is configured for this feature. The email identifies the owner. A bearer token in configuration authenticates calls as that owner. The firewall and the token are the last behavior slices. Until the firewall slice, the route answers without a token. Human login is backlog.

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

The HTTP layer sees one archive service. The orchestrator and storage are inside that service. Names for those inner boundaries are decided in the storage-interface slice.

## Slices

| Order | Slice | Category | Done when | Status |
| --- | --- | --- | --- | --- |
| 1 | [Symfony skeleton](symfony-skeleton.md) | Shell | The PHP namespace is chosen, the app boots, and quality checks pass | Done |
| 2 | Empty route | Shell | `POST /api/archives` answers on a temporary server | Not started |
| 3 | Logging | Shell | A request writes a line on the configured channels | Not started |
| 4 | Payload and mapper | Archive route | A body is accepted or refused before the service runs | Not started |
| 5 | Application service | Archive route | The controller calls that one service and no other collaborator for the command | Not started |
| 6 | ArticleReader | Archive execution | The service fetches the page through the orchestrator | Not started |
| 7 | Storage interface | Archive execution | The service reaches storage through one boundary. The names of that boundary are decided in this slice | Not started |
| 8 | Storage and schema | Archive execution | The owner's SQLite file holds the archive. A second POST of the same URL returns the stored row | Not started |
| 9 | Response | Archive route | Success is `url` and `title`. Failures share one problem document. The schema is generated from the code | Not started |
| 10 | Firewall | Archive route | The archive route requires an authenticated caller | Not started |
| 11 | Token and owner email | Archive route | The configured bearer token authenticates the owner identified by the configured email | Not started |
| 12 | Runtime | Runtime | PHP-FPM behind the Docker reverse proxy serves the app | Not started |

Slices 1–9 answer without a token. Slice 10 is the increment that starts refusing an anonymous call. Slice 11 adds the configured token and the owner email.

## Backlog

Deferred. No slice owns these until this file says one does. When a need shows up, decide then, or leave the item here and reprioritize it.

- `Idempotency-Key`. This POST does not read that header. [research/Types.md](research/Types.md) records the header for a later session.
- Human login. An allowlisted email is proved once, or again after a period, and the device then holds a passkey. [research/Login.md](research/Login.md) records the direction. An external identity provider is documented there and is not the preferred path.
- A second owner, and more than one signed-in person at the same time.
- HTML pages.
- CORS, HTTP caching, CSRF, and a version segment.
- Limits: request size, fetches in flight, and a disk check. Configuration other than the token and the owner email.
- Refreshing a page that has changed. This POST does not do that.
- Image bytes and other later assets. [research/Sqlite.md](research/Sqlite.md) records the open choice.

## Research coverage

A file in `research/` is a direction until a slice spec adopts it. `research/Investigations.md` is an early list of ideas, not a queue. ADRs in [`adr/`](../../adr/) stay proposed until a spec adopts them.

| Topic | Record | What it covers |
| --- | --- | --- |
| Login | [research/Login.md](research/Login.md) | The configured token now, and human login later. |
| First route | [research/Route.md](research/Route.md) | `POST /api/archives`. |
| Controller boundary | [research/Controller.md](research/Controller.md) | The HTTP adapter, the service it calls, and the orchestrator. |
| Archive flow | [research/Flow.md](research/Flow.md) | What the service does with one URL, and the status of each outcome. |
| Types and payload | [research/Types.md](research/Types.md) | Request body, success body, and the error document. |
| Schema | [research/Schema.md](research/Schema.md) | What a schema declares, and which tools check or publish it. |
| Logging | [research/Logging.md](research/Logging.md) | Where a request fact goes, and how the log streams are written. |
| Domain storage | [research/Storage.md](research/Storage.md) | What one accepted archive writes, which owner it belongs to, and how two posts of one URL meet. |
| SQLite | [research/Sqlite.md](research/Sqlite.md) | What SQLite's own docs say about one file per owner, and about a later asset. |
| Symfony controllers | [research/Symfony-controller.md](research/Symfony-controller.md) | A reading of the Symfony controller docs. Not a decision. |
| Research queue | [research/Open.md](research/Open.md) | The closed session list. Implementation starts from this file. |
| Early ideas | [research/Investigations.md](research/Investigations.md) | Ideas that are not a mandatory sequence. |

| ADR | Direction |
| --- | --- |
| [adr/0001-archive-outcome-status.md](../../adr/0001-archive-outcome-status.md) | Failures use 400, 422, 502, and 500. |
| [adr/0002-second-post-returns-stored-archive.md](../../adr/0002-second-post-returns-stored-archive.md) | A second POST of the same URL is 200. |
| [adr/0003-owner-database-is-wal.md](../../adr/0003-owner-database-is-wal.md) | WAL, page size 8192, and a checkpointed export. |
| [adr/0004-archive-lookup-is-per-owner.md](../../adr/0004-archive-lookup-is-per-owner.md) | Lookup is scoped to one owner. |

## Current status

| Area | State |
| --- | --- |
| Discovery | Recorded. The queue in [research/Open.md](research/Open.md) is closed. |
| Next slice | 2 — Empty route. Not started. |
| Contract | [symfony-skeleton.md](symfony-skeleton.md). Status `ready-for-agent`. |
| Current ticket | [01 — Boot Eleanor's kernel in the test environment](issues/01-boot-eleanor-kernel.md). Status `ready-for-agent`. |
| Namespace | `Yumo\Eleanor` on `src/`. `Yumo\Eleanor\Tests` on `tests/`. |
| Application code | `Yumo\Eleanor\Kernel` boots. Symfony 8.1. |
| Specs and plans | [Kernel](specs/Kernel.md). [Plan](plans/2026-10-10-kernel.md). |
