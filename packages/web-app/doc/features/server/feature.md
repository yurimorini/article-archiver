# Feature: Eleanor HTTP server

## Goal

Eleanor is a Symfony application. It exposes a local HTTP API and, later, HTML pages. The first command accepts a URL, runs the ArticleReader orchestrator, and stores the article on disk the operator controls.

People who use Eleanor are known (one operator, or a family) and may be signed in at the same time. The server is not open to the public internet.

A URL pointer and the archived copy of a page stay distinct. Clients (browser, script, phone) are interchangeable against the JSON API.

## Documents

| Document | Role |
| --- | --- |
| [context/Login.md](context/Login.md) | How someone gets in. Decided direction and the options that were compared. |
| [context/Investigations.md](context/Investigations.md) | Remaining discovery sessions, one topic each. The request-block list at the end is a guideline for later decisions. |

Research lives in `context/`. Specs and plans are added when a session closes on a design.

## Decided

| Topic | Choice |
| --- | --- |
| Framework | Symfony. The bootstrap feature left this unset; this feature chooses it. |
| First access implementation | A token in configuration, checked on each request. This exists so the application and the check can be built before human login. |
| Human login | Later. An allowlisted email is proved once, or again every several weeks or months, and the device then holds a passkey. An external identity provider is documented in the login discovery and is not the preferred path. |

## Current status

| Area | State |
| --- | --- |
| Discovery | Login direction is recorded. The sessions in `context/Investigations.md` are open. |
| Namespace | Unset, as in the bootstrap feature. |
| Application code | `src/` and `tests/` are empty. |
