# Research ideas

This file is an early list of generic ideas. It is not a mandatory sequence. Notes in `research/` are ideas of the same kind: nothing here is required, and nothing has to be done in this order. A later document adopts an idea by writing it in its own file.

Login is recorded in [Login.md](Login.md). The route contract is recorded in [Route.md](Route.md). The controller boundary is recorded in [Controller.md](Controller.md). The archive flow is recorded in [Flow.md](Flow.md). This list does not reopen them.

The configured token and the firewall are the first implementation slice. They do not wait on the ideas below. Those ideas sketch the archive command and the surfaces around it.

[Request blocks](#request-blocks) lists the blocks a generic REST call carries. It is a guideline. An adopted document names the blocks it takes. The other blocks stay on the list until a change needs them.

## 1. Contract of the archive command

**Question.** What does the client send, and what does Eleanor return, when it asks to archive one URL?

**Already known.** The first route is a POST that carries a URL. Eleanor calls `Orchestrator::fetchArticle`. That method returns `PipelineSuccess` or `PipelineNoContent`, or throws `OrchestratorException`. Redirects are not followed and a failed download is not retried. The fetch policy caps the download at 10 seconds and 5 MB. A lossy encoding is a warning on success, not a failure.

**Done when.** The request body, the success body, and the empty-outcome body are named, including where `PipelineWarning` appears.

## 2. What is stored

**Question.** What does Eleanor write to disk for one accepted archive, and what does it leave unwritten?

**Already known.** The archived copy and the URL pointer are different things. Data stays on storage the operator controls, in an open, backup-friendly form. `PipelineSuccess` carries a non-empty purified article. `PipelineNoContent` means there is no article body to store.

**Done when.** The files or records that make one archive are listed, and the case "download succeeded, write failed" has an outcome: the client sees an error, and the disk has no truncated copy.

## 3. The same URL again

**Question.** What happens when the same URL is posted twice, including twice at the same moment?

**Already known.** HTTP caching does not apply to this POST. The property to decide is whether a second post refreshes the copy, returns the copy already stored, or refuses because a copy exists. Two overlapping writes of the same URL must not leave a half-written file.

**Done when.** The second post has one outcome, and overlapping posts of one URL are serialized with an atomic write.

## 4. How long the request lives

**Question.** Does the POST run the orchestrator inside the HTTP request, or accept the work and finish it later?

**Already known.** The download is synchronous and bounded (about 10 seconds, plus extraction). A queue and a later status request earn their place when one URL no longer fits that bound. The PHP request has to outlive the orchestrator when they run together, and the client has to know the same bound.

**Done when.** The first version is either a single request that finishes with the archive, or an accepted job plus a way to read its status. The client's deadline is stated.

## 5. Errors the client can rely on

**Question.** How does Eleanor turn the orchestrator's three outcomes into HTTP responses that stay stable?

**Already known.** Success, no article, and a hard abort are different. A hard abort names the stage (`OrchestratorError`). Fine detail stays on the previous exception. Stack traces and filesystem paths stay in the log. JSON clients and, later, HTML pages do not share one error document.

**Done when.** Each outcome has a status and a body. The body for the JSON API is a Problem Details document (RFC 9457). Warnings on an otherwise successful archive stay in the success body.

## 6. Limits

**Question.** What does Eleanor refuse before it calls the orchestrator or writes to disk, and with which status?

**Already known.** The JSON body of this POST is small. The orchestrator already caps the remote page. A script can still start many downloads at once and fill the disk. The cost falls on the remote sites and on the operator's disk. A refusal is part of the contract.

**Done when.** The maximum request size, the maximum number of fetches in flight, and the disk check each have a behavior and an HTTP status.

## 7. Logging

**Question.** What does Eleanor record for one HTTP call, and how long does that record live?

**Already known.** The orchestrator already logs each pipeline hop through PSR-3. Eleanor adds the HTTP round: method, route, outcome, duration, and a correlation id shared with the pipeline log. The article HTML, upstream headers, and the configured token stay out of the log. `application` and `domain` are written on every call, to stderr for now. Eleanor does not send telemetry off the machine. The handler is recorded in [Logging.md](Logging.md).

**Done when.** The fields of one request log line are listed, and the fields that are never logged are listed. Both are recorded in [Logging.md](Logging.md).

## 8. JSON and HTML

**Question.** How do the API and the HTML pages divide the routes, the errors, and the session?

**Already known.** Both can live in one Symfony process. JSON lives under a stable prefix. HTML pages come later and use their own error pages. A browser that calls the API uses the same JSON as a script. HTTP caching, CORS, and CSRF wait until a GET or a cookie-backed form exists. API versioning beyond the prefix waits until the first breaking change. Metrics, tracing, internationalization, and accessibility wait on the same later surfaces.

**Done when.** The prefix and the rule for which routes return JSON are fixed, and the deferred items above stay listed as later work rather than part of the first command.

## 9. Boundary inside Eleanor

**Question.** Which type talks to Symfony, which type runs the archive use case, and which type is the orchestrator?

**Already known.** The orchestrator is the library entry. It does not know about HTTP. The firewall already owns authentication. The controller does not construct the pipeline by hand.

**Done when.** The three roles are named, each with what it accepts and what it returns, and the orchestrator remains behind an application type Eleanor owns.

## 10. Configuration other than the token

**Question.** Which values are configuration, and which stay as code defaults?

**Already known.** The access token is configuration ([Login.md](Login.md)). Candidates beside it: archive directory, bind address, orchestrator timeouts, and the in-flight fetch cap from session 6. The controller does not invent them. The PHP namespace is still unset and is chosen with the first class, not as a behavior of the server.

**Done when.** The configuration keys for the first command are listed, each with what it controls. The namespace decision is recorded next to the bootstrap feature when the first class is added.

## Request blocks

A generic REST call is a fixed set of blocks. Eleanor uses that set when an implementation or an enhancement needs a decision. The ideas above name blocks they might close. A block with no idea, and any detail an idea leaves open, stays listed here until a change needs it. Leaving ideas unadopted leaves the unused rows undecided on purpose.

The controller action is three blocks: typed input, one use case, and the HTTP response. The blocks around the action belong to the request and stay outside that method.

When a change touches a row, record which part of the generic block it follows and which part it leaves for a later enhancement. The row stays the checklist. The document that adopts the choice holds it.

### Inside the action

| Block | Generic project | Eleanor |
| --- | --- | --- |
| Typed input | Body, query, and path become a DTO. An invalid body stops before the use case. | Session 1 names the POST body. |
| Use case | One application service per operation. The controller passes a command and receives a result. The application type is what calls the domain pipeline. | Adopted in [Controller.md](Controller.md). |
| Response | The result becomes status, body, and headers such as `Location` or `ETag` when the resource needs them. | Session 1 names the success body and the empty-outcome body. Session 5 names the status of each outcome. |

### Around the action

| Block | Generic project | Eleanor |
| --- | --- | --- |
| Route contract | Verb, path, media type, and often a version prefix. | Adopted in [Route.md](Route.md). |
| Authentication | A component in front of the controller resolves the caller. The action finds that identity already decided. | [Login.md](Login.md). The firewall checks the configured token, and later a session. |
| Authorization | A policy on this caller, this action, and this resource. | The firewall is the filter for the first command. A policy per resource waits until a stored archive is something a caller can be allowed or refused. |
| Errors | One translator turns validation, domain, and infrastructure failures into Problem Details. | Session 5. JSON uses RFC 9457. HTML pages keep their own error documents. |
| Persistence | The use case talks to storage. The controller receives the outcome. | Session 2 lists what one archive writes. Session 3 decides a second post of the same URL. |
| Limits | Body size, concurrent work, and sometimes an idempotency key. | Session 6: request size, fetches in flight, and the disk check. |
| Observability | A request log, a correlation id, and often metrics and tracing. | [Logging.md](Logging.md) names the HTTP log line. Metrics and tracing wait with the later surfaces in session 8. |
| Browser edges | CORS, HTTP caching, and CSRF appear when a browser, a GET, or a cookie-backed form exists. | Session 8 keeps these off the first command. |
