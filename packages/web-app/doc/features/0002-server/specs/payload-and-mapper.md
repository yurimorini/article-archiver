**Status:** ready-for-agent

## Problem Statement

`POST /api/archives` answers 200 `{"ok":true}` for every body. A client cannot tell a string URL pointer from a body Eleanor will not pass on. Later slices need that refusal to happen before an archive service exists.

## Solution

The same route accepts a JSON object whose `url` is a string, including an empty string, and still returns the empty-route stub. It refuses a malformed JSON body and a JSON value that is not that object with 400. It refuses a missing `Content-Type`, or a media type other than JSON, with 415. Both refusals happen before the action returns the stub. The refusal body is Symfony's JSON error, not Eleanor's problem document. The action does not call an archive service and does not use `url` in the response.

## User Stories

1. As a client of Eleanor, I want `POST /api/archives` with `{"url":"https://example.com/a"}` and `Content-Type: application/json` to return 200 `{"ok":true}`, so that a string URL pointer still proves the path.
2. As a client of Eleanor, I want the response content type of that call to be `application/json`, so that I can read the stub as JSON.
3. As a client of Eleanor, I want `{"url":""}` to return the same stub, so that an empty string is not refused before `UrlGuard`.
4. As a client of Eleanor, I want `{"url":" "}` to return the same stub, so that a string of spaces is not refused here.
5. As a client of Eleanor, I want a string that is not a usable URL, such as `not-a-url`, to return the same stub, so that this slice does not judge the URL pointer.
6. As a client of Eleanor, I want an extra JSON property beside `url` to be ignored and the stub returned, so that a newer client can send a field this server does not know.
7. As a client of Eleanor, I want `Content-Type: application/json; charset=utf-8` with a string `url` to return the stub, so that the charset parameter still counts as JSON.
8. As a client of Eleanor, I want a query string on that POST to leave the match and the stub unchanged, so that the query string stays outside the route.
9. As a client of Eleanor, I want `Accept: text/html` on an accepted body to still return the JSON stub, so that the route does not negotiate a media type.
10. As a client of Eleanor, I want the matched route of an accepted POST to stay `create_archive`, so that the path is the same route as the empty-route slice.
11. As a client of Eleanor, I want a body that is not valid JSON, sent as `application/json`, to be 400, so that a broken body never reaches the action.
12. As a client of Eleanor, I want an empty body sent as `application/json` to be 400, so that an empty document is not treated as a URL pointer.
13. As a client of Eleanor, I want `{}` to be 400, so that a missing `url` is refused.
14. As a client of Eleanor, I want `{"url":null}` to be 400, so that a null URL pointer is refused.
15. As a client of Eleanor, I want `{"url":1}` to be 400, so that a non-string URL pointer is refused.
16. As a client of Eleanor, I want `{"url":true}` to be 400, so that a boolean is not coerced into a string.
17. As a client of Eleanor, I want `{"url":{"href":"https://example.com/a"}}` to be 400, so that an object is not a URL pointer.
18. As a client of Eleanor, I want `{"url":["https://example.com/a"]}` to be 400, so that an array is not a URL pointer.
19. As a client of Eleanor, I want a JSON array body to be 400, so that the body has to be the object with `url`.
20. As a client of Eleanor, I want a JSON string body to be 400, so that a bare string is not the object.
21. As a client of Eleanor, I want a JSON number body to be 400, so that a bare number is not the object.
22. As a client of Eleanor, I want a JSON `null` body to be 400, so that null is not the object.
23. As a client of Eleanor, I want a JSON `true` body to be 400, so that a bare boolean is not the object.
24. As a client of Eleanor, I want every 400 above to use content type `application/json`, so that the error is not an HTML page.
25. As a client of Eleanor, I want those 400 responses to omit Eleanor's `cause`, so that the problem document waits for the response slice.
26. As a client of Eleanor, I want not to depend on the wording of Symfony's error JSON, so that a framework message is not the contract.
27. As a client of Eleanor, I want a valid JSON body with no `Content-Type` to be 415, so that the media type is required even when the octets are JSON.
28. As a client of Eleanor, I want `Content-Type: text/plain` to be 415, so that plain text is not accepted as the body.
29. As a client of Eleanor, I want `Content-Type: application/x-www-form-urlencoded` to be 415, so that a form body is not mapped as the JSON object.
30. As a client of Eleanor, I want an empty body with no `Content-Type` to be 415, so that the missing media type is not reported as a bad JSON body.
31. As a client of Eleanor, I want every 415 to use content type `application/json`, so that this refusal is not an HTML page.
32. As a client of Eleanor, I want 415 to stay distinct from 400, so that a bad media type is not an invalid JSON body.
33. As a client of Eleanor, I want no archive stored on an accepted call or on a refusal, so that a URL pointer and an archive stay distinct.
34. As a client of Eleanor, I want no page fetched, so that this slice does not run the reader.
35. As a client of Eleanor, I want the call to succeed or be refused with no owner and no token, so that authentication stays off until the firewall slice.
36. As a client of Eleanor, I want GET and HEAD on `/api/archives` to stay 405, so that only POST is the command.
37. As a client of Eleanor, I want `POST /api/archives/` to stay off this route, so that the path still has no trailing slash.
38. As a client of Eleanor, I want `/archives` without the prefix to stay off this route, so that the prefix is still required.
39. As a client of Eleanor, I want no version segment in the path, so that the path stays `/api/archives`.
40. As a developer of Eleanor, I want the body mapped before the action runs, so that a refused body never returns the stub.
41. As a developer of Eleanor, I want that mapping to be `MapRequestPayload`, so that the kernel's resolver owns the refusal.
42. As a developer of Eleanor, I want the action method to stay `create` on `CreateArchiveController`, so that this slice does not turn the controller into an invokable class route.
43. As a developer of Eleanor, I want the mapped value to be a body DTO with only `url`, so that no other field reaches the action.
44. As a developer of Eleanor, I want `url` to be a nullable string defaulting to null, with `NotNull` and without `NotBlank`, so that a missing `url` is 400 and `""` still reaches the action.
45. As a developer of Eleanor, I want `acceptFormat` limited to `json`, so that another media type is 415.
46. As a developer of Eleanor, I want `validationFailedStatusCode` set to 400, so that a shape failure is not 422.
47. As a developer of Eleanor, I want unknown JSON properties ignored by the serializer default, so that the route does not reject them.
48. As a developer of Eleanor, I want `symfony/serializer`, `symfony/validator`, and `symfony/property-access` on `8.1.*`, so that the resolver and `NotNull` exist.
49. As a developer of Eleanor, I want no other direct package added, so that the dependency set stays these three plus what the app already has. `symfony/property-info` and `symfony/type-info` stay lock-only.
50. As a developer of Eleanor, I want the action to receive the DTO and not use `url` in the response, so that the stub stays `{"ok":true}`.
51. As a developer of Eleanor, I want the action to call no archive service, so that the service slice is the first caller.
52. As a developer of Eleanor, I want the existing kernel HTTP test of this route to be the only automated seam, so that a second test of the resolver is not added.
53. As a developer of Eleanor, I want that test to keep sending the request through the kernel in the test environment, so that it does not call the action as a function.
54. As a developer of Eleanor, I want the old "any body is 200" cases that this slice refuses to be updated, so that the suite matches the new statuses.
55. As a developer of Eleanor, I want a refusal assertion to check status and `application/json` only, so that Symfony's error text is not locked in.
56. As a developer of Eleanor, I want an accepted assertion to keep checking the route name, the status, the content type, and `{"ok":true}`, so that the stub stays exact.
57. As a developer of Eleanor, I want the kernel boot test kept, so that the boot seam still passes.
58. As a person running Eleanor, I want the README curl to send `Content-Type: application/json` and a JSON object with a string `url`, so that the documented call still returns the stub instead of 415.
59. As a person running Eleanor, I want that documented response to stay `{"ok":true}`, so that the manual check matches the accepted body.
60. As a developer of Eleanor, I want the quality checks to pass, so that the mapping meets the same bar as the route.
61. As a developer of Eleanor, I want no log line added by this slice, so that logging stays in its slice.
62. As a developer of Eleanor, I want no problem document and no `cause` added, so that the response slice owns that document.
63. As a developer of Eleanor, I want no owner email, bearer token, database path, or archive directory added, so that those stay in their slices.

## Implementation Decisions

- This contract adopts [adr/0007](../../../adr/0007-archive-body-mapped-by-map-request-payload.md). [PayloadAndMapper.md](../design/PayloadAndMapper.md) adopts that ADR, so it is accepted. [adr/0001](../../../adr/0001-archive-outcome-status.md) still owns archive outcomes. 415 is the media type, before that command. [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md) stays as the empty-route slice adopted it.
- The route stays `POST /api/archives`, name `create_archive`, action `Yumo\Eleanor\Api\Controller\CreateArchiveController::create`, format `json`, no alias, no version segment, no trailing slash. The import loads `src/Api/Controller/`, not `src/Api/`. The import prefix, `stateless`, and `trailing_slash_on_root` do not change.
- The action argument is mapped with `MapRequestPayload` before the action runs. `acceptFormat` is `json`. `validationFailedStatusCode` is 400.
- The body DTO is not a glossary term. It is `Yumo\Eleanor\Api\Dto\CreateArchiveBody` in `src/Api/Dto/`. Its only property is a nullable string `url` defaulting to null, constrained with `NotNull` and not with `NotBlank`. The DTO does not enter a service. The action does not put `url` in the response.
- A string `url`, including `""` and `" "`, returns 200 and `{"ok":true}` as `application/json`. An extra JSON property is ignored. `application/json; charset=utf-8` is JSON.
- Malformed JSON, an empty body with `Content-Type: application/json`, a missing `url`, a null `url`, a non-string `url`, and a JSON value that is not that object (array, string, number, `null`, boolean) are 400 with content type `application/json`.
- A missing `Content-Type`, `text/plain`, and `application/x-www-form-urlencoded` are 415 with content type `application/json`. An empty body with no `Content-Type` is 415.
- The refusal body is Symfony's JSON error. Its words, its `cause` absence, and any debug header are not the contract. Eleanor's problem document is not built here.
- `symfony/serializer` and `symfony/validator` are added at `8.1.*`. `symfony/property-access` is added at `8.1.*` as well. `symfony/property-info` and `symfony/type-info` stay lock-only. No other direct package is added.
- The README curl sends `Content-Type: application/json` and a JSON object with a string `url`, and the documented body stays `{"ok":true}`. The server command does not change.
- GET and HEAD stay 405. The slash path and the unprefixed path stay off this route. No owner, token, storage, or log channel is added.

## Testing Decisions

- A good test observes the HTTP seam only. It sends a request into the kernel and reads the status, the response content type, and, for an accepted call, the body and the matched route name. It does not call the action as a function, assert the exception class, or assert the text of Symfony's error JSON.
- The seam is the existing kernel HTTP test of `POST /api/archives`, in the test environment. It is extended, not joined by a resolver unit test, a socket test, or a browser test.
- Accepted cases on that seam include a string `url`, an empty `url`, and an extra property. Each expects route `create_archive`, 200, `application/json`, and `{"ok":true}`.
- Refusal cases expect 400 for malformed JSON, a missing `url`, a non-string `url`, and a JSON value that is not the object, and 415 for a missing `Content-Type` and for `text/plain`. Each expects `application/json` and does not expect a body string.
- The empty-route cases that treated a non-JSON body, a missing content type, or a non-string `url` as 200 are updated to these statuses. GET, HEAD, the trailing slash, and the unprefixed path stay as they are. The kernel boot test stays.
- The README curl is a manual check. It is not a second automated seam.

## Out of Scope

- Request logging.
- An archive service, `UrlGuard`, fetching a page, purifying an article, and storing an archive.
- The success body `url` and `title`, the 201 for a new archive, and the 200 for an archive the owner already has.
- Eleanor's problem document, `cause`, and `application/problem+json`.
- The firewall, the bearer token, and the owner email.
- PHP-FPM, the reverse proxy, and the distributable image.
- Request size limits, an idempotency key, HTML pages, CORS, HTTP caching, CSRF, and a version segment.
- A new glossary term. The body DTO is not an archive, an article, or a URL pointer.

## Further Notes

- [PayloadAndMapper.md](../design/PayloadAndMapper.md) adopts adr/0007, so that ADR is accepted. This contract already follows it.
- The 200 on an accepted body is the empty-route stub. It is not the stored-archive 200, and it is not the 201 of a new archive.
- Slice 3, logging, is still the next implementation slice. This contract is slice 4.
