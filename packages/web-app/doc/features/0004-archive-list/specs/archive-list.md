**Status:** ready-for-agent

## Problem Statement

A client of Eleanor can store an archive with `POST /api/archives`, and the success JSON is `url` and `title`. The client cannot list the archives that owner already has, and cannot read one archive. `GET /api/archives` answers 405.

## Solution

`GET /api/archives` returns one page of that owner's archives, metadata only. `GET /api/archives/{id}` returns that archive's metadata, the same fields as one list element. `GET /api/archives/{id}?includeContent=true` returns that metadata plus the purified HTML. `GET /api/archives/{id}.html` returns the stored HTML with no template. `POST /api/archives` adds the integer `id` to the success JSON. `HEAD` on these GET routes returns the GET status and headers and an empty body. A missing archive is 404. An invalid parameter is 400. An empty list is 200 with an empty `items` array. A failure stays `application/json`, including when the success body is HTML.

## User Stories

### The list

1. As a client of Eleanor, I want `GET /api/archives` with the bearer to answer 200 `application/json`, so that the list is a normal API response.
2. As a client of Eleanor, I want an omitted parameter to mean `sort=archived`, `dir=desc`, `page=1`, and `size=10`, so that the first page is the ten archives stored most recently.
3. As a client of Eleanor, I want the response to repeat the applied `sort`, `dir`, `page`, and `size`, so that I see the values the server used.
4. As a client of Eleanor, I want `items` to be an array of archives, so that I read one page.
5. As a client of Eleanor, I want each element to contain `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`, in that order, so that the page is metadata.
6. As a client of Eleanor, I want `id` to be the integer row id, so that I can ask for that archive.
7. As a client of Eleanor, I want `url` to be the URL pointer that names the archive, so that I recognise the page.
8. As a client of Eleanor, I want `title` to be the stored title, including `""` when the page had no title, so that `title` is always present.
9. As a client of Eleanor, I want `excerpt` and `siteName` to be null when the page had none, so that absence is not an empty string.
10. As a client of Eleanor, I want `archived` to be the row insert time in UTC, the text the database stored, so that I see the same instant `sort=archived` uses.
11. As a client of Eleanor, I want no HTML in a list element, so that a page of archives stays small.
12. As a client of Eleanor, I want `total` to be the number of archives, not the number of pages, so that I know how many archives the owner has.
13. As a client of Eleanor, I want `size` in the response to repeat the requested size on the last page, so that a short last page does not change `size`.
14. As a client of Eleanor, I want `next` to be the path of the following page, or null when this page is the last, so that I can continue.
15. As a client of Eleanor, I want `next` to be `/api/archives?sort=archived&dir=desc&page=2&size=10` for a bare `GET /api/archives` that has an eleventh archive, so that the link carries the applied defaults.
16. As a client of Eleanor, I want `next` to write `sort`, `dir`, `page`, and `size` in that order, so that every link has one shape.
17. As a client of Eleanor, I want to send those four parameters in any order, so that the order of my query does not matter.
18. As a client of Eleanor, I want an unknown query parameter to be ignored, so that a parameter this server does not know does not fail the call.
19. As a client of Eleanor, I want `sort=archived` to be the only accepted sort token, so that a later token can be added to the same list.
20. As a client of Eleanor, I want `sort=created_at` to answer 400 `invalid_query`, so that a column name is not a token.
21. As a client of Eleanor, I want `dir=asc` to list the oldest insert time first, so that I can walk forward in time.
22. As a client of Eleanor, I want `dir=desc` to list the newest insert time first, so that the default direction is available explicitly.
23. As a client of Eleanor, I want two archives with the same insert time to be ordered by id in the same direction, so that a page does not swap those two archives between calls.
24. As a client of Eleanor, I want `page=5` to skip the earlier pages, so that I can open a later page without reading the earlier ones.
25. As a client of Eleanor, I want a new archive stored between page 1 and page 2 to be allowed to repeat or skip an archive on page 2, so that page numbers stay page numbers.
26. As a client of Eleanor, I want an owner with no archives to answer 200 with `items` `[]`, `total` 0, and `next` null, so that an empty list is not an error.
27. As a client of Eleanor, I want a missing database file to be that empty list, so that the first call before any POST still answers 200.
28. As a client of Eleanor, I want `page=11` with 98 archives and `size=10` to answer 200 with `items` `[]`, `total` 98, and `next` null, so that a page past the last page is not an error.
29. As a client of Eleanor, I want `size=100` to be accepted when the configured maximum is 100, so that the maximum itself is a legal page.
30. As a client of Eleanor, I want `size=101` to answer 400 `invalid_query` and not a shorter page, so that I learn the cap instead of receiving a silent cut.
31. As a client of Eleanor, I want the maximum to be configuration whose starting value is 100, so that an operator can raise or lower the cap without a code change.
32. As a client of Eleanor, I want `dir=ASC`, `page=0`, `page=-1`, `page=1.5`, `page=abc`, `size=0`, and `size=-1` to answer 400 `invalid_query`, so that a value outside the allowed set is refused.
33. As a client of Eleanor, I want the message of `invalid_query` to be "A parameter has an invalid value.", so that the sentence is fixed.
34. As a client of Eleanor, I want a title such as `Café`, a URL with slashes, and a title with a quote to be valid JSON with unescaped slashes and unescaped Unicode, so that I do not decode the list myself.

### One archive

35. As a client of Eleanor, I want `GET /api/archives/17` to answer 200 `application/json` when 17 is that owner's archive, so that I can read its metadata.
36. As a client of Eleanor, I want that body to contain `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`, in that order, so that the detail metadata is one list element.
37. As a client of Eleanor, I want that body to have no `html` key, so that a metadata read does not carry the article.
38. As a client of Eleanor, I want `GET /api/archives/17?includeContent=true` to answer 200 `application/json` with those six fields and `html` last, so that one call returns the archive and the purified HTML.
39. As a client of Eleanor, I want `html` on that call to be the purified article HTML as a string, including when it contains a quote or a `<`, so that the JSON stays valid and I can render the archive.
40. As a client of Eleanor, I want an omitted `includeContent` to mean the metadata body, `includeContent=true` to mean the body with `html`, and any other value to answer 400 `invalid_query` with the message "A parameter has an invalid value.", so that the flag is exact.
41. As a client of Eleanor, I want `GET /api/archives/17.html` to answer 200 `text/html; charset=UTF-8` whose body is the stored HTML and nothing else, so that I can show the article without a template.
42. As a client of Eleanor, I want a missing row, on the metadata route, on `includeContent=true`, and on `.html`, to answer 404 `archive_not_found` with the message "The archive does not exist.", so that absence is one code.
43. As a client of Eleanor, I want that 404 when the database file is missing too, so that a missing file is a missing archive.
44. As a client of Eleanor, I want a segment that is not a positive integer, such as `abc`, `0`, `-3`, `1.5`, `01`, or `abc.html`, to answer 400 `invalid_query` with the message "A parameter has an invalid value.", so that a bad id is not a missing archive.
45. As a client of Eleanor, I want `GET /api/archives/`, `GET /api/archives/17.json`, and an unknown path to stay 404 `not_found`, so that a trailing slash, a `.json` suffix, and a wrong path stay different from a missing archive.

### The archive command

46. As a client of Eleanor, I want a new archive to answer 201 with `id`, `url`, and `title`, so that I receive the id of the row just written.
47. As a client of Eleanor, I want a second POST of the same URL pointer to answer 200 with the same `id`, `url`, and `title`, so that a repeat returns the existing row.
48. As a client of Eleanor, I want that second POST to keep the same id when the title or the HTML is later replaced, so that the id is the row and not the text.
49. As a client of Eleanor, I want the POST JSON to contain only `id`, `url`, and `title`, so that the excerpt, the site name, the insert time, and the HTML stay out of the command.
50. As a client of Eleanor, I want `id` to be a JSON number, so that I do not parse a string.

### Methods, auth, and errors

51. As a client of Eleanor, I want `HEAD /api/archives`, `HEAD /api/archives/17`, and `HEAD /api/archives/17.html` to return the GET status and headers and no body, so that I can read the status without the body.
52. As a client of Eleanor, I want `PUT` and `DELETE` on the list, on `/api/archives/{id}`, and on `/api/archives/{id}.html` to answer 405 `method_not_allowed` with `Allow`, so that I learn the methods.
53. As a client of Eleanor, I want a call without the bearer to answer 401 `unauthenticated` the way the other `/api` routes do, so that the list does not open the API.
54. As a client of Eleanor, I want every failure to stay `application/json` with `code` and `message`, including a failure of `.html`, so that I parse one error shape.
55. As a client of Eleanor, I want a 400 and a 404 not to write an error line, so that a bad parameter does not look like a server fault.
56. As a client of Eleanor, I want a request that reached the action to carry `X-Request-Id`, so that I can quote it.
57. As a client of Eleanor, I want only this owner's archives, so that another owner's file is not this list.

### Developer behavior

58. As a developer of Eleanor, I want no new table and no schema version, so that the existing id and insert time are enough.
59. As a developer of Eleanor, I want the list to read the archive row and not the HTML, so that a page does not load every article.
60. As a developer of Eleanor, I want the metadata read to use the archive row and not the HTML, and `includeContent=true` and `.html` to read the stored HTML, so that a metadata call does not load the article.
61. As a developer of Eleanor, I want the store to return the id after a save and on a later read, so that the POST and the list share one id.
62. As a developer of Eleanor, I want the quality checks to pass for `web-app`, so that the routes meet the same bar as the archive command.

## Implementation Decisions

- [adr/0010](../../../adr/0010-archive-list-is-a-page.md), [adr/0011](../../../adr/0011-archive-id-is-public.md), and [adr/0012](../../../adr/0012-archive-detail-is-read-by-id.md) are the decisions this spec adopts. The glossary sentence that an integer id refers to the archive stays.
- The list and the detail are GET routes under `/api`. The list is the collection. The detail is one id, as metadata, as metadata plus HTML, or as the stored HTML. `POST /api/archives` stays the archive command and gains `id`.
- There is no new table and no schema version. The integer id and the insert time already exist. The list and the bare detail read the archive metadata and do not read the HTML. `includeContent=true` and `.html` read the stored HTML.
- `sort=archived` maps to the insert time. `dir` is `asc` or `desc`. Rows are ordered by that time and then by the id, both in `dir`. The id is not a sort token.
- An omitted parameter means `sort=archived`, `dir=desc`, `page=1`, and `size=10`. The response repeats those applied values. The client may send the four parameters in any order. An unknown query parameter is ignored.
- `next` is a path beginning with `/api/archives` and the query `sort`, `dir`, `page`, `size` in that order, or null when the page is the last or past the last. `total` is the archive count. `size` in the response is the requested size.
- The maximum `size` is configuration. The starting value is 100. A larger `size` is 400 `invalid_query`. The server does not shrink the page.
- A present `sort` outside the allow-list, a `dir` other than `asc` or `desc`, a `page` below 1, a `size` below 1, and a `page` or `size` that is not an integer are 400 `invalid_query` with the message "A parameter has an invalid value." A page past the last page is 200 with `items` `[]`.
- A list element is `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`, in that order. `excerpt` and `siteName` are null when absent. `siteName` is the stored site name. `archived` is the stored UTC insert time. The element has no HTML.
- The bare detail is one list element and has no `html` key. `includeContent=true` adds `html` last, the purified article HTML, always a string. Any other `includeContent` value is 400 `invalid_query`. `.html` answers `text/html; charset=UTF-8` with the stored HTML and no template. A query string on `.html` is ignored. The id segment uses the same integer rule as `page` and `size`: base-10 digits, no sign, no decimal point, no leading zero, at least 1, and it fits in a PHP int. A missing row, including a missing database file, is 404 `archive_not_found` with the message "The archive does not exist." A bad id is 400 `invalid_query`. A trailing slash, a `.json` suffix, and an unknown path stay 404 `not_found`.
- The POST success JSON is `id`, `url`, and `title`. A new archive is 201. An archive already stored is 200 with the same id. The excerpt, the site name, the insert time, and the HTML stay out. A later replacement of the title or the HTML keeps the id. A new database file, or a new row after the old row is gone, assigns another id.
- `HEAD` on the list, the detail, and `.html` returns the GET status and headers and an empty body. Other methods stay 405 `method_not_allowed` with `Allow`.
- The bearer and the owner are unchanged. The routes read the authenticated owner's store. Failures under `/api` keep `code` and `message`. A 400 and a 404 do not write an error line. A request that reached the action carries `X-Request-Id`.
- The store returns the id from a save and from a read. A test stand-in that saves archives returns an id too, so the POST contract does not depend on SQLite alone.
- The list document is the document a later search reuses. This feature adds no search parameter and no second list shape.

## Testing Decisions

- A good test observes the status, the content type, the JSON, and the headers. It does not assert SQL, a private call order, or a class name.
- The seam is the kernel test of `/api`, with the bearer and the fetcher double, the same seam as `POST /api/archives`. Archiving URL pointers through that seam prepares the rows. The test proves the list, the three detail representations, the id on POST, `HEAD` with an empty body, each 400, the 404 codes, the empty list, and a page past the last page.
- Two archives stored in one second prove the id tie-break, because the insert time alone does not order them.
- The test sets the page-size maximum when it proves that the maximum is refused and that the maximum itself is accepted.
- `Café`, a slash in a URL, a quote in a title, and a quote or a `<` in `html` are proved on this seam. The `.html` body equals the stored HTML. The bare detail has no `html` key.
- Unit tests do not boot the kernel. The quality checks for `web-app` pass.
- A browser, a network fetch, and an OpenAPI document are not seams.

## Out of Scope

- A previous-page link, an opaque continuation token, and a cursor that is the URL.
- Reading an archive by URL. `POST /api/archives` remains the way to learn that a URL pointer is already stored.
- Search, fuzzy finding, and any filter other than the page.
- A UUID, a new primary key, and a schema migration.
- A template around the stored HTML, caching, and a rendered article. `GET /api/archives/{id}.html` returns the stored fragment and is in scope.
- OpenAPI.
- A change to the bearer, the owner, or the error document's shape.
- `prev` from the removed backlog note. The note's `page` past the end as 404, `sort=creation`, and `GET /api/archives/list` are not this contract.

## Further Notes

- The backlog file `list.md` is removed. Its draft disagreed with this contract on the path, the sort token, the page past the end, and the wrapper named `cursor`. This spec is the contract.
- Until this feature ships, `GET` and `HEAD` on `/api/archives` stay 405, and the POST stays `url` and `title`. [error-response.md](../../0002-server/specs/error-response.md) and [archive-command.md](../../0002-server/specs/archive-command.md) say so.
