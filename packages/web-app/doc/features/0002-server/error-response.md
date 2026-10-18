**Status:** ready-for-agent

## Problem Statement

`POST /api/archives` archives a URL pointer, but a failure has no usable shape for the client. A library failure is not translated, so Eleanor answers with Symfony's own error page. A refused body (400, 415), a wrong method (405), and an unknown path (404) each answer in Symfony's default JSON, whose wording and fields Eleanor does not own. A client cannot tell a page that is not an article from a URL Eleanor refuses, from an upstream site that is down, from a fault in Eleanor, without reading prose. The success body is built by hand, and nothing describes the API in a machine-readable form that stays current with the code.

## Solution

Every failure under `/api` answers with the same small JSON object: a stable `code` and one `message` sentence. The HTTP status says the family of the failure. The success body stays `{"url","title"}`. One serializer writes both. The API description is an OpenAPI document generated from the code, committed to the repository, and checked against the code by a test. The clients Eleanor serves (browser, script, phone) branch on the status first and on `code` second, and show `message`.

## User Stories

### Success

1. As a client of Eleanor, I want a new archive to answer 201 with `{"url","title"}` as `application/json`, so that the success contract of the archive command is unchanged.
2. As a client of Eleanor, I want an archive that is already stored to answer 200 with the same JSON, so that a repeat is not an error and has the same shape.
3. As a client of Eleanor, I want a title such as `Café` and a URL with slashes to arrive as readable UTF-8 and unescaped slashes, so that I do not decode `\u00e9` or `\/` myself.
4. As a client of Eleanor, I want a title with a quote or a backslash to stay valid JSON, so that the body is encoded and never concatenated.
5. As a client of Eleanor, I want the success JSON to contain only `url` and `title`, so that the excerpt, the site name, and the article stay in the archive.

### The error shape

6. As a client of Eleanor, I want every failure under `/api` to answer `application/json` with exactly `code` and `message`, so that I parse one shape for every error.
7. As a client of Eleanor, I want the HTTP status to carry the family of the failure and the body not to repeat it, so that the status has one source.
8. As a client of Eleanor, I want `code` to be a stable lowercase token, so that my program branches on it and not on prose.
9. As a client of Eleanor, I want the same cause to always produce the same `code`, so that a retry or a second client sees the same answer.
10. As a client of Eleanor, I want `message` to be one English sentence that is safe to show a person, so that I can display it.
11. As a client of Eleanor, I want `message` never to contain a stack trace, a file path, article HTML, a credential, or the owner email, so that an error does not leak the server.
12. As a client of Eleanor, I want `message` to be fixed for a `code`, except that an upstream status failure names the upstream status number, so that the text does not change between two calls with the same cause.
13. As a client of Eleanor, I want the request id to stay in the `X-Request-Id` response header and not in the body, so that the error body has no field that exists only for debugging.
14. As a client of Eleanor, I want a request that reached the action to carry `X-Request-Id` on an error response as it does on a success, so that I can quote it when I report a failure.

### Refused requests

15. As a client of Eleanor, I want a body that is not valid JSON, sent as `application/json`, to answer 400 `invalid_json`, so that a broken body is told apart from a wrong one.
16. As a client of Eleanor, I want `{}`, `{"url":null}`, a non-string `url`, and a JSON value that is not an object to answer 400 `invalid_body`, so that a wrong body is told apart from a broken one.
17. As a client of Eleanor, I want a URL string with an unusable syntax to answer 400 `url_syntax`, so that I know to fix the string.
18. As a client of Eleanor, I want a URL that Eleanor's rules refuse, such as a scheme or credentials that are not allowed, or an address that is not public, to answer 400 `url_policy`, so that I know the pointer is refused by policy and not by syntax.
19. As a client of Eleanor, I want any other refusal of the URL string to answer 400 `url_rejected`, so that no refusal falls outside a 400.
20. As a client of Eleanor, I want a missing `Content-Type` and `text/plain` to answer 415 `unsupported_media_type`, so that a wrong media type is told apart from a wrong body.
21. As a client of Eleanor, I want `GET` and `HEAD` on the archive route to answer 405 `method_not_allowed` and keep the `Allow` header, so that I learn which method the route accepts.
22. As a client of Eleanor, I want a `HEAD` answer to carry the status and headers and no body, so that the response follows HTTP.
23. As a client of Eleanor, I want an unknown path under `/api` to answer 404 `not_found`, so that a wrong path has the same error shape.

### Pages that cannot be archived

24. As a client of Eleanor, I want a page with no article to answer 422 `extract_no_content`, so that "this page is not an article" is not a server fault.
25. As a client of Eleanor, I want a page whose article is empty after purification to answer 422 `sanitized_empty`, so that I can tell it from a page with no article.
26. As a client of Eleanor, I want a redirect, which Eleanor does not follow, to answer 422 `redirect`, so that I know to send the final URL.
27. As a client of Eleanor, I want a response that is not HTML to answer 422 `content_type`, so that a PDF or an image is a page that cannot be archived and not a fault.
28. As a client of Eleanor, I want a body over the size cap to answer 422 `body_too_large`, so that I know the cap is the reason.

### Faults of the upstream site and of Eleanor

29. As a client of Eleanor, I want a transport failure to the page's site to answer 502 `transport`, so that I know the site could not be reached.
30. As a client of Eleanor, I want an HTTP error status from the page's site to answer 502 `upstream_status` with the upstream number in `message`, so that I can tell a 404 page from a 503 page.
31. As a client of Eleanor, I want a character-encoding failure to answer 500 `encoding`, so that Eleanor's own failure is a 500.
32. As a client of Eleanor, I want a failure of article extraction to answer 500 `extract`, and a failure of purification to answer 500 `sanitize`, so that each stage that aborts has its own code.
33. As a client of Eleanor, I want any other failure, including a programming error, to answer 500 `unexpected` with one fixed generic sentence, so that nothing from the inside reaches me.

### What a failure does to the archive

34. As a client of Eleanor, I want a failed POST to store nothing, so that a failure is never an archive.
35. As a client of Eleanor, I want a later POST of the same URL pointer, after a 422, 502, or 500, to fetch again and answer 201 when it succeeds, so that a failure does not block a retry.

### Developer behavior

36. As a developer of Eleanor, I want every failure of the archive command to be one `ArchiveException` that carries a failure kind and the original cause, so that the HTTP layer reads the kind and never a library type.
37. As a developer of Eleanor, I want one mapper to turn the library's stage failures, their finer reasons, and an empty outcome into that failure kind, so that the translation lives in one tested place.
38. As a developer of Eleanor, I want `ArchiveService` to catch only the library's pipeline exception, so that a programming `Error` is not renamed and becomes a 500 `unexpected`.
39. As a developer of Eleanor, I want the failure kind to be an enum whose values are the wire codes, so that a code cannot be misspelled in two places.
40. As a developer of Eleanor, I want one listener on `kernel.exception` to translate every exception for a path under `/api`, so that the controller does not catch.
41. As a developer of Eleanor, I want a pure translator from an exception to a status and an error body, so that each code is proved without the kernel.
42. As a developer of Eleanor, I want the success and the error to be two different response types, so that `title` of an archive and `message` of an error are never mixed.
43. As a developer of Eleanor, I want a generic reply type that pairs a status with a body type, so that the controller and the translator return the same kind of object and one responder writes the JSON.
44. As a developer of Eleanor, I want one serializer of Eleanor's to write the success and the error with the same JSON options, so that a title is encoded the same way in both.
45. As a developer of Eleanor, I want a 502 and a 500 to write the original failure to the `application` log channel, so that the operator sees the cause the client cannot.
46. As a developer of Eleanor, I want a refusal that is the client's fault (400, 404, 405, 415, 422) not to write an error line, so that the log has no noise from a bad request.

### The API description

47. As a client developer, I want an OpenAPI document of Eleanor's API generated from the code, so that I can read the contract and generate a client from it.
48. As a client developer, I want that document to describe the success body and every error status of `POST /api/archives` with the `code` values for each status, so that I know which codes to handle.
49. As a client developer, I want the request body described as an object with a required string `url`, so that the schema matches the payload rules.
50. As a developer of Eleanor, I want the document committed to the repository and regenerated with one command, so that it is reviewed like code.
51. As a developer of Eleanor, I want a test that fails when the committed document differs from the generated one, so that the description stays current with the code.
52. As a developer of Eleanor, I want the generator to be a development dependency only, so that the running server has no new package.

### Documentation and quality

53. As a user of the README, I want the README to show a failing call and where the OpenAPI document is, so that the documented calls match the server.
54. As a developer of Eleanor, I want the two leftovers of slice 5 that name this slice to be closed and removed from `feature.md`, so that nothing stays open without an owner.
55. As a developer of Eleanor, I want the quality checks to pass for `web-app`, so that the command meets the same bar as the earlier slices.

## Implementation Decisions

- This contract replaces the error document that [research/Types.md](research/Types.md) and [research/Schema.md](research/Schema.md) describe: the `application/problem+json` object with `status`, `cause`, `title`, and `detail`. The replacement is `application/json` with `code` and `message`. The wire shape of a success stays as [archive-command.md](archive-command.md) set it. The row name in [feature.md](feature.md) changes from "Problem document" to "Error response", and `feature.md` records why.
- The shape was chosen from the client's side. The client reads the status first. `code` separates causes inside one status. One `message` sentence is what a person sees. A `status` member would repeat the HTTP status. A separate `title` would be fixed per `code` and derivable from it. A request id is already on every answer of a routed request in the `X-Request-Id` header, so it is not in the body.
- Success and error bodies are flat. There is no envelope. Adding a field to a success document is additive for clients that ignore unknown fields, and an envelope could not be adopted later without breaking them. A list endpoint will carry its own document.
- The status of each failure family follows [adr/0001](../../adr/0001-archive-outcome-status.md): 400 for a refused request and a refused URL, 422 for a page that cannot be archived, 502 for the page's site, 500 for Eleanor. 404, 405, and 415 are the statuses the framework already uses for an unknown path, a wrong method, and a wrong media type.
- The codes by status are: 400 `invalid_json`, `invalid_body`, `url_syntax`, `url_policy`, `url_rejected`; 404 `not_found`; 405 `method_not_allowed`; 415 `unsupported_media_type`; 422 `extract_no_content`, `sanitized_empty`, `redirect`, `content_type`, `body_too_large`; 502 `transport`, `upstream_status`; 500 `encoding`, `extract`, `sanitize`, `unexpected`. `archive_not_written` waits for the SQLite slice, because the in-memory store cannot fail a write.
- `ArchiveException` carries an `ArchiveFailure` and the original cause. `ArchiveFailure` is a string-backed enum whose values are the codes of the archive failures. A mapper in the archive module turns the library's `OrchestratorException`, its stage and its finer reason, and a `PipelineNoContent` reason into an `ArchiveFailure`. Only that mapper and the adapter name the library's error types for this purpose.
- `ArchiveService` catches the library's `OrchestratorException` and nothing broader. Any other throwable passes through unchanged and the listener answers it as 500 `unexpected`. This closes the leftover on `\Throwable`.
- The HTTP layer has two response types, one for the success (`url`, `title`) and one for the error (`code`, `message`). A generic reply type pairs an HTTP status with a body type. The controller returns a reply of the success type. The translator returns a reply of the error type. The generic parameter is documented for the static analyzer. It does not exist in the JSON.
- An error translator maps an exception to a reply of the error type. An `ArchiveException` uses its failure kind. A request body that fails validation is `invalid_body`. A body that is not parseable is `invalid_json`. The framework's not-found, method-not-allowed, and unsupported-media-type exceptions use their own codes. Any other throwable is 500 `unexpected` with one fixed sentence. The exact signal that tells `invalid_json` from `invalid_body` is confirmed against the framework in the spec.
- One listener on `kernel.exception` handles requests whose path is under `/api` and writes the translated reply. It keeps the headers the framework attached to the exception, such as `Allow`. It writes the original failure to the `application` log channel for 502 and 500, and writes nothing for the other statuses. A path outside `/api` is not touched.
- One serializer of Eleanor's takes a response object and returns a JSON string. It wraps the framework serializer, and fixes the JSON options for unescaped slashes and unescaped Unicode in one place. The controller and the listener use it and pass the string with the status to `JsonResponse::fromJsonString`. It does not implement the framework serializer interface.
- The OpenAPI document is generated by `zircote/swagger-php` as a development dependency. Fields of the response types are taken from their PHP types. The route, the statuses, and the codes per status are declared with attributes on the controller. The request body type declares `url` as a required string, because its PHP type alone would read as optional and nullable. The document is committed to the repository and is regenerated with one command. The generator does not run in the running server. The spec confirms the output version and the file location.
- No other new package is added. No runtime schema validation of a request or a response is added. The request body check stays the validator on the body object from the payload slice.
- The glossary terms are unchanged. `Archive`, `URL pointer`, and `Owner` keep their meaning. `ArchiveFailure` and the response types are types, not glossary terms.

## Testing Decisions

- A good test observes status, content type, and the JSON body at its seam. It does not assert Symfony's error text, a private call order, or a class name of a library exception.
- The highest seam is the existing kernel test of `POST /api/archives`. It drives the route through the kernel with the `ArticleFetcher` double. It covers each status and each `code` of the table above, the content type, and the exact `code` and `message` keys. It reads the `X-Request-Id` header on an answered request and the `Allow` header on a 405. It covers `Café` and a URL with slashes in the success body. It proves that a failed call stores nothing and that the same URL archives after a failure. The tests that expected Symfony's JSON for 400, 415, and 405 change to the new body. A test with an unknown path covers 404. A throwing double that raises an `Error` covers 500 `unexpected` and proves the fixed sentence does not carry the failure's text.
- Unit tests do not boot the kernel. The mapper has a case for each stage, each finer reason, and each empty reason. The translator has a case for each code and for an unknown throwable. The serializer has cases for `Café`, a quote, and slashes. `ArchiveService` proves that a pipeline exception becomes `ArchiveException` with its kind and cause, and that an `Error` passes through. The listener test proves that a path outside `/api` is untouched, that a 502 and a 500 are logged, and that a 4xx writes no error line.
- One test regenerates the OpenAPI document and compares it with the committed file. It fails when they differ.
- Prior art: the kernel test of the archive route, the archive service test with `FakeArticleFetcher` and `PipelineFixtures`, and the production wiring test that proves the container.
- The library's own tests stay the proof of what the orchestrator raises. This slice does not retest fetching.
- A real network fetch, a browser, and a response-versus-schema validator are not seams.

## Out of Scope

- `application/problem+json`, `type`, `title`, `detail`, `instance`, and any other member of that standard. They are not adopted. A member can be added later, because clients ignore members they do not know.
- 401 and 403, which belong to the firewall and token slices.
- `archive_not_written` and any failure of a durable store, which belong to the SQLite slice.
- The idempotency key and its 409 and 422.
- Translation or localization of `message`.
- A list of field violations for an invalid body.
- `Retry-After` and links from a `code` to documentation.
- A request or response validator that runs in the server.
- HTML pages and errors outside `/api`.
- A new log line for a success, and any change to the request lines of the logging slice.

## Further Notes

- [feature.md](feature.md) records the change of the row name and the replaced research notes. The module spec is the next step. It confirms the signal that tells an unparseable body from an invalid one, the OpenAPI version and file location, and the exact attributes.
- A request that matches no controller does not receive `X-Request-Id` today, because the request line subscriber starts at the controller event. This contract keeps that behavior. A change belongs to the logging feature.
- The two slice 5 leftovers that name this slice, on the `\Throwable` catch and on the non-ASCII title, are closed by this contract and deleted from `feature.md` when the slice is done.
