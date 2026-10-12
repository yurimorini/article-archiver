**Status:** ready-for-agent

## Problem Statement

Eleanor boots, and it has a front controller, but it has no archive route. A client cannot send `POST /api/archives` and see that the HTTP path answered. Later slices need that path in place before they read a URL pointer, store an archive, or require a caller.

## Solution

Add one stateless JSON route, `POST /api/archives`, that answers before any archive exists. Every POST on that path returns 200 and `{"ok":true}` as `application/json`, whatever the body is. The action does not read the URL pointer and does not store an archive. A WebTestCase proves the response. The application README tells a person how to start a temporary server and send one request. Logging, payload refusal, and the real archive command stay in their own slices.

## User Stories

1. As a client of Eleanor, I want `POST /api/archives` to answer, so that I can see the HTTP path is alive before an archive exists.
2. As a client of Eleanor, I want that answer to be 200, so that I can tell the empty route from a missing path.
3. As a client of Eleanor, I want the response content type to be `application/json`, so that I can read the body as JSON.
4. As a client of Eleanor, I want the response body to be `{"ok":true}`, so that I can tell this stub from the later success body that carries a URL pointer and a title.
5. As a client of Eleanor, I want the same body when I send no body, so that the empty route does not depend on a payload.
6. As a client of Eleanor, I want the same body when the body is not JSON, so that this slice does not refuse a payload.
7. As a client of Eleanor, I want the same body when I send a JSON URL pointer, so that a realistic call still only proves the HTTP path.
8. As a client of Eleanor, I want the same body when the `url` value is not a string, so that this slice does not judge the URL pointer.
9. As a client of Eleanor, I want the same body when `url` is absent from a JSON object, so that a missing URL pointer is not a failure yet.
10. As a client of Eleanor, I want the same body when I send a JSON array or a JSON scalar, so that only the path and the verb matter.
11. As a client of Eleanor, I want the same body when I omit `Content-Type`, so that this slice does not require a media type.
12. As a client of Eleanor, I want the same body when `Content-Type` is not `application/json`, so that refusal of the media type waits for the payload slice.
13. As a client of Eleanor, I want the same body when I send `Accept: text/html`, so that the route does not negotiate a media type.
14. As a client of Eleanor, I want the same body when the path has a query string, so that the query string is not part of the match.
15. As a client of Eleanor, I want no archive to be stored, so that a URL pointer and an archive stay distinct.
16. As a client of Eleanor, I want no article to be read, so that this call does not fetch a page.
17. As a client of Eleanor, I want the call to succeed with no owner and no token, so that authentication stays off until the firewall slice.
18. As a client of Eleanor, I want another verb on `/api/archives` to be refused with 405, so that only POST is the command.
19. As a client of Eleanor, I want HEAD on that path to be 405 as well, so that HEAD is not treated as a silent POST.
20. As a client of Eleanor, I want `POST /api/archives/` to miss this route, so that the path has no trailing slash and POST is not redirected onto it.
21. As a client of Eleanor, I want no version segment in the path, so that the path stays `/api/archives`.
22. As a client of Eleanor, I want `/archives` without the prefix to miss this route, so that the prefix is not optional.
23. As a client of Eleanor, I want this route to be the one named `create_archive`, so that a test can see the path landed on that route.
24. As a developer of Eleanor, I want the action path to be only `/archives`, so that the prefix is not copied onto the action.
25. As a developer of Eleanor, I want the `/api` prefix on one import of the API controllers, so that later JSON routes join the same group.
26. As a developer of Eleanor, I want that import to be stateless, so that an API request does not use the session.
27. As a developer of Eleanor, I want `trailing_slash_on_root` false on that import, so that a future empty API path stays `/api` and does not become `/api/`.
28. As a developer of Eleanor, I want POST and the JSON format declared on the action, so that an import-level method list cannot replace them.
29. As a developer of Eleanor, I want the JSON format on the action, so that the request format is JSON without reading the request `Content-Type`.
30. As a developer of Eleanor, I want no route alias, so that `create_archive` is the only name.
31. As a developer of Eleanor, I want the API controllers in their own namespace under the application namespace, so that the import does not take every future controller.
32. As a developer of Eleanor, I want the catch-all controller import removed, so that the same action is not also registered without the prefix.
33. As a developer of Eleanor, I want the catch-all to stay gone when HTML arrives, so that API controllers are not imported twice.
34. As a developer of Eleanor, I want HTML routes to arrive in a later import without this prefix and without `stateless`, so that HTML stays outside `/api`.
35. As a developer of Eleanor, I want the action to be a method on one API controller, so that the route is not an invokable class route.
36. As a developer of Eleanor, I want that method to do nothing but return the stub JSON, so that the controller has no archive collaborator.
37. As a developer of Eleanor, I want the action not to map the request payload, so that validation stays in the payload slice.
38. As a developer of Eleanor, I want the action not to touch the session, so that the stateless import does not fail the request.
39. As a developer of Eleanor, I want one in-process HTTP test to prove the route, so that a regression fails when the path stops answering.
40. As a developer of Eleanor, I want that test to send the request through the kernel, so that it does not call the action as a plain function.
41. As a developer of Eleanor, I want that test to force the test environment, so that it does not boot in dev.
42. As a developer of Eleanor, I want the browser-kit package on the 8.1 line as a dev dependency, so that the HTTP test client exists.
43. As a developer of Eleanor, I want the test pack left out, so that the dev dependencies stay the ones this slice needs.
44. As a developer of Eleanor, I want the existing kernel boot test kept, so that the boot seam still passes.
45. As a person running Eleanor, I want the temporary server documented in the application README, so that I can open the route without PHP-FPM.
46. As a person running Eleanor, I want that server to be PHP’s built-in server inside the development image, so that I do not install the Symfony CLI.
47. As a person running Eleanor, I want the server bound to the loopback on port 8000, so that this slice is not reachable from the LAN.
48. As a person running Eleanor, I want the documented server to forward the path to the front controller, so that `/api/archives` is not a file the built-in server has to find.
49. As a person running Eleanor, I want one curl example in that README, so that I can see the stub JSON from the temporary server.
50. As a person running Eleanor, I want the development image left as it is, so that this slice does not become the distributable image.
51. As a developer of Eleanor, I want the quality checks to pass, so that the route meets the same bar as the kernel.
52. As a developer of Eleanor, I want the dev error route left as the skeleton wired it, so that it is not replaced by this archive route.
53. As a developer of Eleanor, I want no owner email and no bearer token added, so that those stay in the token slice.
54. As a developer of Eleanor, I want no database path and no archive directory added, so that storage stays in its slice.

## Implementation Decisions

- This contract adopts the stateless `/api` import in adr/0005. The ADR stays proposed until the module spec adopts it.
- API controllers live in the namespace `Yumo\Eleanor\Controller\Api`, on the directory that namespace maps to. One import loads that directory with prefix `/api`, `trailing_slash_on_root` false, and `stateless` true.
- The catch-all that loads every routed controller service is removed. It is not restored when a later HTML import is added. That later import has no `/api` prefix and is not stateless. This slice does not add it.
- The action is `Yumo\Eleanor\Controller\Api\CreateArchiveController::create`. Its path is `/archives`, its name is `create_archive`, its only method is POST, and its format is `json`. It has no alias.
- POST and the format are declared on the action. The import does not set methods or format. An import method list would replace the action’s methods.
- The finished path is `/api/archives`, with no trailing slash and no version segment. A POST to the slash form is not this route. The query string is not part of the match.
- The action returns 200 and the JSON object `{"ok":true}` with content type `application/json`. It returns that for every body: absent, not JSON, a JSON URL pointer of any JSON type, a JSON object without `url`, a JSON array, and a JSON scalar. It returns that for a missing request content type, a non-JSON content type, and an `Accept` that is not JSON.
- The action does not read the body, does not map the payload, does not call an archive service, does not fetch a page, and does not touch the session.
- A verb other than POST on the same path, including HEAD, is 405.
- `/archives` without the prefix is not this route.
- The dev dependency added for the test client is `symfony/browser-kit` on `8.1.*`. The test pack is not added. No other package is added.
- The temporary server is PHP’s built-in server in the existing development image, bound to loopback port 8000, with a router script that forwards requests to the front controller. The application README documents the command and one curl. The Symfony CLI is not installed. The development image is not changed into a distributable image.
- The skeleton’s kernel, environments, front controller, dev error route, and quality scripts stay. This slice adds no owner email, bearer token, database path, or archive directory.

## Testing Decisions

- A good test observes the HTTP seam only. It sends a request into the kernel and reads the status, the response content type, the body, and the matched route name. It does not call the action as a function, open a socket, start the built-in server, or assert the text of the route attribute.
- The cases on that seam are: POST with no body, POST with a non-JSON body, POST with a JSON URL pointer, POST with a non-string `url`, one other verb that must be 405, and a POST whose path is not `/api/archives`. The route name asserted for the successful POST is `create_archive`.
- The test runs in the existing web-app PHPUnit suite, in the test environment. The kernel boot test stays. The library suite is not the seam.
- The curl against the temporary server is a manual check documented in the README. It is not a second automated seam. A browser driven by an agent is not a seam.

## Out of Scope

- Request logging.
- Refusing a content type, rejecting a body, and mapping the payload. A wrong content type is not 415 here, and an unreadable body is not 400 here.
- Reading a URL pointer, fetching a page, purifying an article, and storing an archive.
- The success body `url` and `title`, the 201 for a new archive, and the 200 for an archive the owner already has.
- The firewall, the bearer token, and the owner email.
- PHP-FPM, the reverse proxy, and the distributable image.
- The Symfony CLI, a real-browser end-to-end test, and an agent-driven browser check.
- HTML pages, CORS, HTTP caching, CSRF, a version segment, and an idempotency key.
- Request size limits and other configuration beyond this route.
- A new glossary term. `{"ok":true}` is not an archive, an article, or a URL pointer.

## Further Notes

- adr/0005 stays proposed until the module spec adopts it. This contract already follows it.
- The 200 on this route is the empty-route stub. It is not the stored-archive 200, and it is not the 201 of a new archive.
- The path `/api` remains the JSON prefix. This slice is the first route under it.
