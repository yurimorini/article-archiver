# Remaining sessions

Start here when continuing the HTTP server design in a fresh session. One session closes one item below and writes the outcome in its own file under `research/`. Do not append the outcome to [Investigations.md](Investigations.md). That file is an early list of generic ideas, not this queue and not a mandatory order.

A session is an interview. The reader chooses the direction. Facts come from the code and from primary docs. The outcome is research, not a final decision. A later session can replace it. A spec adopts it when implementation starts. Terms that the session settles go in the repository `GLOSSARY.md`. An ADR is written only when a choice would be hard to reverse, surprising without the context, and the result of a real trade-off. During research it stays proposed. An ADR for this feature lives in [`adr/`](../adr/).

## Recorded

| Topic | Record |
| --- | --- |
| Login direction | [Login.md](Login.md) |
| First route | [Route.md](Route.md) |
| Controller boundary | [Controller.md](Controller.md) |
| Archive flow | [Flow.md](Flow.md) |
| Symfony controller docs, not a decision | [Symfony-controller.md](Symfony-controller.md) |

## Next, in this order

### 1. Types and payload

The request field that carries the URL. The success body. The error document, including the body of the 400 for an invalid payload. Value objects, the application result, and the response DTO. The shape of the serializer attributes.

Already fixed around this item, and not to be reopened: the body DTO stays in the controller; the service receives a URL; `UrlGuard` runs only inside the orchestrator; the controller maps the result to a response DTO and returns `JsonResponse::fromJsonString` of Eleanor's own serializer. Status codes are recorded in [Flow.md](Flow.md). An invalid body is 400 from `#[MapRequestPayload]`, via `validationFailedStatusCode`, before `__invoke` runs.

### 2. Logging

What Eleanor records for one HTTP call, and how long that record lives. The orchestrator already logs each pipeline hop. The article HTML, upstream headers, and the configured token stay out of the log.

### 3. Storage

What one accepted archive writes to disk, what it leaves unwritten, and what a second POST of the same URL does, including two posts at the same moment. The archived copy and the URL pointer stay distinct. [Flow.md](Flow.md) already says a success is the copy on disk, and `PipelineNoContent` does not write.

## Later, not part of the three items above

Limits (request size, fetches in flight, disk check) and configuration other than the token. HTML pages, CORS, HTTP caching, CSRF, and a version segment. Evolution stays the preference until an incompatible change is needed.
