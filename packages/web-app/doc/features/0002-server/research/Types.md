# Archive types and payload

This file records a research session. It is not a final decision. A later session can replace it. A spec adopts it when implementation starts. The field list of the success body is confirmed at spec time; the direction below is what this session settled.

## Request

The JSON body is an object with one string property, `url`. A property Eleanor does not know is ignored, and the request continues. That is a deliberate break with the usual advice to reject unknown request fields: clients (browser, script, phone) are not released together with the server, and the route has no version segment. Ignoring an added field lets an older server accept a newer client. A typo of `url` itself is still rejected, because the property is then missing.

Missing `url`, a value that is not a string, a body that is not that object, or a body that is not JSON: invalid body, 400, before the service runs. Any string, including an empty one, is given to the service. `UrlGuard` is the only judge of that string.

## Success body

[RFC 9110, 201 Created](https://www.rfc-editor.org/rfc/rfc9110.html#name-201-created) says the body describes the resource created. `Location` waits until an archive has an address, and there is no GET of one archive, so this POST is the only description the client receives.

The body carries a fact the client did not send. The client sent the URL. Eleanor found the title. The client asked to store the HTML, not to receive it back. A pipeline warning, such as a lossy encoding, stays out of the body: the client cannot act on it.

The direction for the spec is the minimum:

```json
{"url":"https://example.com/a","title":"Title"}
```

`title` is always present. When extraction finds none, it is `""`. `siteName`, `excerpt`, and the article HTML stay out until a reading of the archive exists. Adding a response field later does not break clients: they ignore fields they do not know. On a success, `SafeDocument` also holds `siteName`, `excerpt`, and `html`. Those are the values a later read can add.

The 200 for an archive already stored uses this same JSON. The title then comes from the stored record. [Storage.md](Storage.md) records the rest of that record.

## Second POST of the same URL

One owner has one archive of a page, named by its URL. This is not an idempotency key. The client is asking again for an archive of that page.

The service checks the store before the pipeline.

- The archive is already stored: the pipeline does not run, and nothing is written. The response is 200 with the same JSON a 201 would have carried, read from the record.
- The archive is not stored: the pipeline runs, a `PipelineSuccess` is written, and the response is 201.

201 means this request wrote the copy. Two posts that both miss the row may both fetch. The first insert wins, and the other reads the stored row and takes the 200 path. [Storage.md](Storage.md) records that race.

A later command can refresh a page that has changed. This POST does not.

The key, the 409, and the fingerprint 422 below stay out of this contract. A key could at most select a status of its own. It does not replace the rule above.

## Idempotency key

Recorded so a later session can add it. This POST does not read `Idempotency-Key`.

A key recognizes one request. The client sends it when it retries an attempt whose response it never saw. The client generates the key, normally a UUID, and sends it in the `Idempotency-Key` header. The same key must not be reused with a different body. Eleanor would tell the bodies apart with a fingerprint it computes, such as a checksum of the body.

The record is application state, not an archive and not a URL pointer. Its job is to recognize that this request has already arrived. It holds the key, the fingerprint, and the response already produced, status and body. It lives in the application store, which [Storage.md](Storage.md) keeps separate from an owner's file. It does not need a database of its own.

The expired IETF draft [draft-ietf-httpapi-idempotency-key-header-07](https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-idempotency-key-header-07) (not an RFC; it expired on 18 April 2026) describes the four cases:

| Arrival | What Eleanor would do |
| --- | --- |
| Key never seen | Run the command and store the response on the record |
| Same key, same fingerprint, command finished | Return the stored response. The pipeline does not run |
| Same key, a different body | 422. This attempt does not run. The first response stays the first request's response |
| Same key, the first attempt still in progress | 409 |

`cause` on the error document would tell that 422 apart from a page that is not an article.

## Error document

Every failure under `/api` uses one document, including the 400 from `#[MapRequestPayload]` before `__invoke`. The content type is `application/problem+json`.

```json
{
  "status": 400,
  "cause": "url_policy",
  "title": "URL rejected",
  "detail": "URL scheme or credentials are not allowed"
}
```

`status` repeats the HTTP status. `cause` is the stable token the client branches on. `title` is a short label. `detail` is one sentence that is safe to show: no stack, no filesystem path, no article HTML, and no credential. For `unexpected`, including the translator that runs when mapping or serializing throws, `detail` is one fixed generic sentence. For `upstream_status`, `detail` includes the upstream code, such as `404`.

| Status | `cause` |
| --- | --- |
| 400 body | `invalid_json`, `invalid_body` |
| 400 URL | `url_syntax`, `url_policy`, `url_rejected` |
| 422 | `extract_no_content`, `sanitized_empty`, `redirect`, `content_type`, `body_too_large` |
| 502 | `transport`, `upstream_status` |
| 500 | `encoding`, `extract`, `sanitize`, `unexpected`, `archive_not_written` |

The success response stays `application/json`. The route still does not negotiate a media type.

## Headers

User-Agent, Referer, and a request id are headers. They are not fields of the archive command.

A listener in front of every route under `/api` reads them, before the action, into a log context. If the client sends a request id, the listener keeps it. Otherwise Eleanor generates one. The controller does not read those headers. The archive service receives the owner and the URL. Which of those values are written to the log, and how long that line lives, is the logging session.

The firewall still authenticates the caller. The edge builds the owner from that caller. [Storage.md](Storage.md) records the owner the service receives.

## Types

The body DTO stays in the controller. It holds the string `url` and nothing else that Eleanor reads.

The service receives the owner and that string. There is no URL value object in Eleanor. `UrlGuard`, inside the orchestrator, judges the string.

The service result has no status, no headers, and no JSON. It is one of three: archive written, with the URL and the title; archive already stored, with the URL and the title read from the record; or a failure, with the `cause` token. `PipelineSuccess` and `PipelineNoContent` stay inside the service.

The controller maps the result to a response DTO and returns `JsonResponse::fromJsonString` of Eleanor's serializer. The success DTO carries `url` and `title`. The error DTO carries `status`, `cause`, `title`, and `detail`. They are different types. Both have a property that serializes as `title`: the extracted title on the success DTO, the short failure label on the error DTO.

Serialization attributes live on the response DTO and name those wire fields. The concrete attribute classes are chosen when the serializer is specified.

The invalid-body 400 is built on the kernel path that handles `MapRequestPayload`, and it uses the same error document.
