# Archive flow

This file records a research session. It is not a final decision. A later session can replace it.

`POST /api/archives` runs the fetch and the archive write inside the HTTP request. The response leaves when that work has finished. There is no queue, and the process does not continue after the connection closes. Eleanor sets no deadline of its own. The web server's limit is the bound. If that limit kills the process, Eleanor produces no result.

The library stays as it is. `Orchestrator::fetchArticle` returns `PipelineSuccess` or `PipelineNoContent`, or throws `OrchestratorException`. The library CLI keeps catching that exception. A queue can be reconsidered when one URL no longer fits the fetch bound (10 seconds, plus extraction).

## What the service does

The service receives a URL. It calls `fetchArticle`. It writes only after `PipelineSuccess`.

Success is an archive: the copy on disk. A pipeline warning, such as a lossy encoding, stays on that success and does not remove the archive. A URL pointer alone is not a result of this command. Recording a URL without a copy would be another command.

`PipelineNoContent` and `OrchestratorException` do not reach the write. Neither stores a URL pointer. A failed write does not report an archive. A throwable the service cannot classify is a failure with a generic reason.

The service returns one of those results. The result has no status, headers, or JSON. `PipelineSuccess` and `PipelineNoContent` stay inside the service. The failure carries the fine reason the library already has: `UrlGuardError`, `HttpFetcherError`, `PipelineEmptyReason`, the stage that aborted, `OrchestratorError::Unexpected`, the failed write, or the generic reason.

What one accepted archive writes, and what a second POST of the same URL does, stay open. They are the storage session.

## Status

The action maps the service result to a response DTO and returns `JsonResponse::fromJsonString` with the status below. The division is recorded in [the ADR](../adr/0001-archive-outcome-status.md).

| Outcome | Status |
| --- | --- |
| Archive written | 201 |
| Invalid JSON body | 400 |
| URL rejected (`UrlGuardError`: `Syntax`, `Policy`, `Rejected`) | 400 |
| Soft: no article (`PipelineEmptyReason`), redirect not followed, content type not HTML, body over the cap | 422 |
| Transport failure, or an upstream HTTP status, including an upstream 404 | 502 |
| Encoding, extraction, or purification aborted; `OrchestratorError::Unexpected`; archive not written; generic reason | 500 |

An invalid body is 400 from `#[MapRequestPayload]` before `__invoke` runs. `validationFailedStatusCode` sets that 400. `HttpFetcherError::Transport` mixes timeouts with other network failures, so none of them is 504 until the library separates a timeout. `Location` waits until an archive has an address.

The URL field name, the JSON bodies, and the shape of the serializer attributes stay open. They are the types session.

## After the service returns

If the action throws while mapping or serializing, one translator for every route under `/api` returns the generic error as JSON, status 500. The action does not catch that throw. The body of that error is part of the error document, which stays open.
