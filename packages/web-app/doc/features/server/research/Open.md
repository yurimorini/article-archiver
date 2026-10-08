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
| Types and payload | [Types.md](Types.md) |
| Request facts and the log line | [Logging.md](Logging.md) |
| Schema checks | [Schema.md](Schema.md) |
| Domain storage | [Storage.md](Storage.md) |
| Symfony controller docs, not a decision | [Symfony-controller.md](Symfony-controller.md) |

## Later, not part of the queue

### Still deferred

Limits (request size, fetches in flight, disk check) and configuration other than the token. HTML pages, CORS, HTTP caching, CSRF, and a version segment. Evolution stays the preference until an incompatible change is needed.
