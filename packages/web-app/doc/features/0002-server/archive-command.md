**Status:** ready-for-agent

## Problem Statement

`POST /api/archives` still returns 200 `{"ok":true}` after it accepts a URL pointer. A client cannot ask Eleanor to keep an archive of that page, and a second call cannot get the archive already stored. The route has no owner, so a later store cannot tell one person's copy from another's.

## Solution

The same route, for one owner whose email is a Symfony parameter, archives the URL pointer. The first call fetches the page, stores the archive in memory, and returns 201 with `url` and `title`. A second call for the same URL pointer returns 200 with the same JSON and does not fetch again. The body DTO stays out of the service. A library failure becomes an application exception. The problem document, the serializer, the schema, and the SQLite file wait.

## User Stories

1. As a client of Eleanor, I want `POST /api/archives` with `{"url":"https://example.com/a"}` and `Content-Type: application/json` to return 201, so that a new archive is a write.
2. As a client of Eleanor, I want that response content type to be `application/json`, so that I can read the body as JSON.
3. As a client of Eleanor, I want that body to be `{"url":"https://example.com/a","title":"Title"}` when the fetched page's title is `Title`, so that I receive the URL pointer I sent and the title Eleanor found.
4. As a client of Eleanor, I want `title` to be `""` when the fetched page has no title, so that `title` is always present.
5. As a client of Eleanor, I want the JSON to contain only `url` and `title`, so that the excerpt, the site name, and the article HTML stay in the archive.
6. As a client of Eleanor, I want a title that contains a quote or a backslash to stay valid JSON, so that the body is encoded and not concatenated.
7. As a client of Eleanor, I want `url` in the JSON to be the string I sent, so that a different string inside the fetch result is not the stored name.
8. As a client of Eleanor, I want a second POST of the same URL pointer to return 200 with the same JSON, so that the archive already stored is the result.
9. As a client of Eleanor, I want that second POST not to fetch the page again, so that a repeat does not refresh the page.
10. As a client of Eleanor, I want a POST of a different URL pointer to return 201 and fetch that page, so that each URL pointer has its own archive.
11. As a client of Eleanor, I want an extra JSON property beside `url` to be ignored, so that a newer client can send a field this server does not know.
12. As a client of Eleanor, I want `Content-Type: application/json; charset=utf-8` to archive the page, so that the charset parameter still counts as JSON.
13. As a client of Eleanor, I want a query string on that POST to leave the match and the archive unchanged, so that the query string stays outside the route.
14. As a client of Eleanor, I want `Accept: text/html` on a successful call to still return the JSON body, so that the route does not negotiate a media type.
15. As a client of Eleanor, I want the matched route of a successful POST to stay `create_archive`, so that the path is the same route as the empty-route slice.
16. As a client of Eleanor, I want no bearer token on these calls, so that authentication stays off until the firewall slice.
17. As a client of Eleanor, I want the owner email to stay out of the JSON, so that the email is not part of the success body.
18. As a client of Eleanor, I want `{"url":""}` not to return `{"ok":true}` and not to return the success JSON, so that an empty string is no longer the stub and is not a stored archive.
19. As a client of Eleanor, I want `{"url":" "}` not to return the success JSON, so that a string of spaces is judged by the fetch, not stored as an archive.
20. As a client of Eleanor, I want `{"url":"not-a-url"}` not to return the success JSON, so that a rejected URL pointer is not an archive.
21. As a client of Eleanor, I want a fetch that finds no article not to return the success JSON, so that a miss is not stored.
22. As a client of Eleanor, I want a fetch that fails not to return the success JSON, so that a failure is not an archive.
23. As a client of Eleanor, I want those failures not to be Eleanor's problem document, so that statuses 400, 422, 502, and 500 and `cause` wait for the problem-document slice.
24. As a client of Eleanor, I want a body that is not valid JSON, sent as `application/json`, to stay 400, so that a broken body never reaches the archive command.
25. As a client of Eleanor, I want `{}`, `{"url":null}`, a non-string `url`, and a JSON value that is not the object to stay 400, so that the payload rules of the previous slice stay.
26. As a client of Eleanor, I want a missing `Content-Type` and `text/plain` to stay 415, so that the media type rule stays.
27. As a client of Eleanor, I want those 400 and 415 responses to stay `application/json` and not to lock Symfony's error text, so that the refusal body stays the previous contract.
28. As a client of Eleanor, I want GET and HEAD to stay 405, so that this route remains POST only.
29. As a developer of Eleanor, I want one Symfony parameter to hold the owner email, so that the route has an owner before the token slice.
30. As a developer of Eleanor, I want an `OwnerProvider` at the HTTP edge to build the `Owner` from that email, so that the controller and the service never read the parameter and the firewall slice swaps the provider alone.
31. As a developer of Eleanor, I want `Owner` to keep the email it is given, so that the identifier is the glossary's email.
32. As a developer of Eleanor, I want `ArchiveService` constructed with one `ArchiveStore` and one `ArticleFetcher`, so that `archive` does not take the owner, the store is already that owner's, and the service does not name a final library class.
33. As a developer of Eleanor, I want `archive` to take the URL string from the body DTO, so that the DTO does not enter the service.
34. As a developer of Eleanor, I want no interface on `ArchiveService`, so that a second implementation is the reason to add one.
35. As a developer of Eleanor, I want `ArchiveServiceFactory::create` to build the service for an `Owner`, so that a CLI can make the same object without HTTP.
36. As a developer of Eleanor, I want `ArchiveServiceFactory` to hold no state and to get each store from an `ArchiveStoreFactory` that takes the `Owner`, so that a later SQLite factory replaces the in-memory one without a change to the service or its factory.
37. As a developer of Eleanor, I want two stores made for the same owner to see the same archives, so that a second call sees the first archive.
38. As a developer of Eleanor, I want a store made for another owner, even from the same shelf, not to see the first owner's archives, so that two owners stay two lists.
39. As a developer of Eleanor, I want `find` and `save` to take the archive and the URL string, not the owner, so that the store is already that owner's context.
40. As a developer of Eleanor, I want `find` of an unknown URL pointer to report absence, so that the first call fetches.
41. As a developer of Eleanor, I want `save` then `find` to return the same archive, so that the memory store is the copy just written.
42. As a developer of Eleanor, I want the first `archive` of a new URL pointer to fetch once, save, and return `Written`, so that 201 means this call wrote the copy.
43. As a developer of Eleanor, I want the second `archive` of that URL pointer to skip the fetch and return `AlreadyStored`, so that 200 means the copy was already there.
44. As a developer of Eleanor, I want the stored `Archive` to hold `url`, `title`, `excerpt`, `siteName`, and `html`, so that the later SQLite adapter can save the same shape.
45. As a developer of Eleanor, I want `title`, `excerpt`, and `siteName` stored as plain strings, so that the archive does not depend on the library's text type.
46. As a developer of Eleanor, I want `excerpt` and `siteName` to stay null when the fetch has none, so that absence is not an empty string.
47. As a developer of Eleanor, I want `html` to be the purified article, so that the archive keeps the article and the URL pointer as different fields.
48. As a developer of Eleanor, I want a pipeline warning, such as a lossy encoding, left out of the archive, so that a warning does not change the stored copy.
49. As a developer of Eleanor, I want a thrown library failure wrapped in `ArchiveException` with that failure as the cause, so that the caller does not name a library exception.
50. As a developer of Eleanor, I want a fetch that finds no article to become `ArchiveException` without storing, so that a miss is not a library value leaking out.
51. As a developer of Eleanor, I want the controller not to catch `ArchiveException`, so that the problem-document slice is the translator.
52. As a developer of Eleanor, I want the controller to map `Written` to 201 and `AlreadyStored` to 200 through `JsonResponse::fromJsonString`, so that this slice does not add Eleanor's serializer.
53. As a developer of Eleanor, I want the orchestrator's own tests to stay in the library, so that this slice does not retest fetching.
54. As a developer of Eleanor, I want the HTTP test to drive `POST /api/archives` through the kernel with an `ArticleFetcher` double, so that the route is proved without a network fetch.
55. As a developer of Eleanor, I want unit tests of `Owner`, of the in-memory `ArchiveStore` and its factory, of `ArchiveServiceFactory`, and of `ArchiveService`, so that each dependency and the service are proved without the kernel.
56. As a developer of Eleanor, I want the README curl to show a successful archive response of `url` and `title`, so that the documented call is no longer the stub.
57. As a developer of Eleanor, I want the quality checks to pass, so that the command meets the same bar as the route.

## Implementation Decisions

- This contract follows the slice merge in [feature.md](feature.md). It replaces the research that put the owner on `archive` and on every store call. [adr/0002](../../adr/0002-second-post-returns-stored-archive.md) is the 200 for an archive already stored. [adr/0004](../../adr/0004-archive-lookup-is-per-owner.md) is lookup scoped to one owner. Both stay proposed until the module spec adopts them. [adr/0007](../../adr/0007-archive-body-mapped-by-map-request-payload.md) stays accepted: the body map is unchanged. [adr/0001](../../adr/0001-archive-outcome-status.md) waits for the problem-document slice.
- The route stays `POST /api/archives`, name `create_archive`. The body DTO stays a nullable string `url` with `NotNull` and without `NotBlank`. The DTO does not enter the service. The action reads the string and passes that string.
- One Symfony container parameter holds the owner email. A fixed value in `services.yaml` sets it, and the test environment overrides it. `OwnerProvider` is an interface in the HTTP layer with one method that returns the `Owner` of the current call. `ConfiguredOwnerProvider` builds that `Owner` from the parameter. The controller takes `OwnerProvider` and does not read the parameter. The firewall and token slices add a provider that builds the `Owner` from the authenticated caller, and change one alias. The controller, the service, and the factory stay as they are. `Owner` keeps that email and nothing else. The service and the store do not read the parameter.
- `InMemoryArchiveStore`, its factory, and `InMemoryArchiveShelf` are a temporary stand-in for the SQLite slice. They live together in one directory and are named stand-ins in their docs. The SQLite slice removes them from `src/` or moves them to the tests. The test environment sets the parameter. The JSON does not contain the email.
- `ArticleFetcher` is a web-app interface with one method that takes the URL string and returns the library's `PipelineSuccess` or `PipelineNoContent`. `ArticleReaderAdapter` implements it by calling the library `Orchestrator`, which is `final` and cannot be doubled. The tests fake `ArticleFetcher`. The library does not change. The adapter is not retested for fetching.
- `ArchiveService` is a concrete class with no interface. Its constructor takes one `ArchiveStore` and one `ArticleFetcher`. It does not hold the `Owner`: the store is already bound to it. `archive` takes the URL string and returns `ArchiveResult`.
- `ArchiveServiceFactory::create` takes an `Owner` and returns an `ArchiveService`. The factory holds no state. It holds one `ArticleFetcher` and one `ArchiveStoreFactory`, and passes the owner's store from that factory to the service. A caller outside HTTP, including a CLI, uses this construction. The service does not know Symfony.
- `ArchiveStoreFactory::create` takes an `Owner` and returns an `ArchiveStore`. This slice's `InMemoryArchiveStoreFactory` holds no state. It makes an `InMemoryArchiveStore` from the `Owner` and one shared `InMemoryArchiveShelf`. The shelf is a container service that holds the archives by owner email. It stands in for the directory of owner files that the SQLite slice adds. The SQLite slice replaces the factory, the store, and the shelf. It does not change `ArchiveService`, `ArchiveServiceFactory`, or `ArticleReaderAdapter`.
- `ArchiveStore` is constructed with one owner. `find` takes the URL string and returns the archive or absence. `save` takes the archive. Neither takes the owner. Two stores do not share rows. This slice's store is memory. It does not open a file. The service does not know SQLite.
- `Archive` holds `url`, `title`, `excerpt`, `siteName`, and `html`. `url` is the string passed to `archive`. `title`, `excerpt`, and `siteName` are plain strings taken from the fetch. `excerpt` and `siteName` are null when the fetch has none. `html` is the purified article. A pipeline warning is not stored.
- `ArchiveResult` holds the `Archive` and an `ArchiveOutcome` of `Written` or `AlreadyStored`. The first store of a URL pointer is `Written`. A later call that finds it is `AlreadyStored` and does not fetch. The action maps those to 201 and 200. The body is JSON `url` then `title`, written with `JsonResponse::fromJsonString`. The content type stays `application/json`.
- A thrown library failure becomes `ArchiveException` whose cause is that failure. A fetch that finds no article becomes `ArchiveException` and stores nothing. The action does not catch it. This slice does not build the problem document.
- An empty string, a string of spaces, and any other string still pass the body map. The archive command then runs. Success is no longer `{"ok":true}`.
- Refusals before the command stay as the payload slice left them: 400 and 415, `application/json`, Symfony's error JSON, words not contracted. GET and HEAD stay 405. No bearer token, no firewall, no new log line, and no new direct package.

## Testing Decisions

- A good test observes behavior at its seam. It does not assert a private call order, a file path, or the text of Symfony's error JSON.
- The HTTP seam is the existing kernel test of `POST /api/archives`, in the test environment. It sends the request through the kernel and reads the status, the content type, and, for a success, the body and the route name `create_archive`. An `ArticleFetcher` double replaces the real fetch. The test can see how many times that double was asked to fetch. The in-memory store and shelf are the real ones. The client does not reboot the kernel between the two POSTs, so the shelf survives. The test does not call the action as a function and does not assert a library exception class.
- On that seam, a new URL pointer is 201, `application/json`, and `{"url","title"}` with `url` first. The second POST of that URL pointer is 200 with the same JSON and does not fetch again. A different URL pointer fetches and is 201. A title with a quote is valid JSON. An empty `url`, a string of spaces, `not-a-url`, a fetch that finds no article, and a thrown fetch failure are not that success JSON and are not Eleanor's problem document. The cases that expected `{"ok":true}` for any string are updated. The 400, 415, GET, and HEAD cases stay. The kernel boot test stays.
- Unit tests do not boot the kernel. `Owner` keeps the email it is given. `ConfiguredOwnerProvider` returns an `Owner` with the email it is given. The in-memory `ArchiveStore` reports absence, returns a saved archive from `find`, and does not share rows with a second store. Two `InMemoryArchiveStore` objects made for the same owner from one shelf see the same archives. `ArchiveServiceFactory` holds no state and gets the store from the store factory. `ArchiveService` returns `Written` after one fetch and a save, returns `AlreadyStored` without a second fetch, stores `url`, `title`, `excerpt`, `siteName`, and `html` as plain values, leaves a warning out, wraps a thrown library failure in `ArchiveException` with that cause, and turns a fetch that finds no article into `ArchiveException` without saving.
- The library's orchestrator tests stay the proof of fetching. This slice does not add a copy of them. A socket test, a browser test, and a real network fetch are not seams.
- The README curl is a manual check of the success body. It is not another automated seam.

## Out of Scope

- Eleanor's problem document, `cause`, `application/problem+json`, and the statuses 400, 422, 502, and 500 for an archive failure.
- Eleanor's serializer and a schema generated from the code.
- The SQLite file, its schema, WAL, and a second POST that survives a new process.
- The firewall, the bearer token, and replacing the email parameter with the authenticated caller.
- A second owner, a refresh of a changed page, and an idempotency key.
- A new log line. The request log from the logging slice stays.
- PHP-FPM, the reverse proxy, and the distributable image.
- HTML pages, CORS, HTTP caching, CSRF, and a version segment.
- A new glossary term. `Owner` and `Archive` stay the glossary's terms. `ArchiveResult` and `ArchiveOutcome` are types, not glossary terms.

## Further Notes

- [feature.md](feature.md) records the slice merge this contract implements. Where [research/Controller.md](research/Controller.md), [research/Flow.md](research/Flow.md), or [research/Storage.md](research/Storage.md) still pass the owner on each call, this file wins.
- The module spec is the next step. It adopts the proposed ADRs it needs. The plan names each test method.
- The 200 in this contract is the archive already stored. It is not the empty-route stub.
