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
| [adr/0001-archive-outcome-status.md](adr/0001-archive-outcome-status.md) | Research direction for why those outcomes use 400, 422, 502, and 500. Not final. |
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
| Archive flow | The POST stores the archive inside the request. Outcomes use 201, 400, 422, 502, and 500. Detail in [research/Flow.md](research/Flow.md). |

## Current status

| Area | State |
| --- | --- |
| Discovery | Login, the first route, the controller boundary, and the archive flow are recorded. The remaining sessions are listed in [research/Open.md](research/Open.md). `research/Investigations.md` holds ideas that are not yet adopted. |
| Namespace | Unset, as in the bootstrap feature. |
| Application code | `src/` and `tests/` are empty. |
