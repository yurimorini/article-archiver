**Status:** ready-for-agent

## Problem Statement

A client of Eleanor can store an archive with `POST /api/archives`, and the success JSON is `url` and `title`. The client cannot list the archives that owner already has, and cannot read one archive with its purified HTML. `GET /api/archives` answers 405.

## Solution

`GET /api/archives` returns one page of that owner's archives, metadata only. `GET /api/archives/{id}` returns one archive, including the purified HTML. `POST /api/archives` adds the integer `id` to the success JSON. `HEAD` on the two GET routes returns the GET status and headers and an empty body. A missing archive is 404. An invalid parameter is 400. An empty list is 200 with an empty `items` array.

## User Stories

### The list

1. As a client of Eleanor, I want `GET /api/archives` with the bearer to answer 200 `application/json`, so that the list is a normal API response.
2. As a client of Eleanor, I want an omitted parameter to mean `sort=archived`, `dir=desc`, `page=1`, and `size=10`, so that the first page is the ten archives stored most recently.
3. As a client of Eleanor, I want the response to repeat the applied `sort`, `dir`, `page`, and `size`, so that I see the values the server used.
4. As a client of Eleanor, I want `items` to be an array of archives, so that I read one page.
5. As a client of Eleanor, I want each element to contain `id`, `url`, `title`, `excerpt`, `site`, and `archived`, so that the page is metadata.
6. As a client of Eleanor, I want `id` to be the integer row id, so that I can ask for that archive.
7. As a client of Eleanor, I want `url` to be the URL pointer that names the archive, so that I recognise the page.
8. As a client of Eleanor, I want `title` to be the stored title, including `""` when the page had no title, so that `title` is always present.
9. As a client of Eleanor, I want `excerpt` and `site` to be null when the page had none, so that absence is not an empty string.
10. As a client of Eleanor, I want `site` to be the public name of the stored site name, so that the response does not publish the column name.
11. As a client of Eleanor, I want `archived` to be the row insert time in UTC, the text the database stored, so that I see the same instant `sort=archived` uses.
12. As a client of Eleanor, I want no HTML in a list element, so that a page of archives stays small.
13. As a client of Eleanor, I want `total` to be the number of archives, not the number of pages, so that I know how many archives the owner has.
14. As a client of Eleanor, I want `size` in the response to repeat the requested size on the last page, so that a short last page does not change `size`.
15. As a client of Eleanor, I want `next` to be the path of the following page, or null when this page is the last, so that I can continue.
16. As a client of Eleanor, I want `next` to be `/api/archives?sort=archived&dir=desc&page=2&size=10` for a bare `GET /api/archives` that has an eleventh archive, so that the link carries the applied defaults.
17. As a client of Eleanor, I want `next` to write `sort`, `dir`, `page`, and `size` in that order, so that every link has one shape.
18. As a client of Eleanor, I want to send those four parameters in any order, so that the order of my query does not matter.
19. As a client of Eleanor, I want an unknown query parameter to be ignored, so that a parameter this server does not know does not fail the call.
20. As a client of Eleanor, I want `sort=archived` to be the only accepted sort token, so that a later token can be added to the same list.
21. As a client of Eleanor, I want `sort=created_at` to answer 400 `invalid_query`, so that a column name is not a token.
22. As a client of Eleanor, I want `dir=asc` to list the oldest insert time first, so that I can walk forward in time.
23. As a client of Eleanor, I want `dir=desc` to list the newest insert time first, so that the default direction is available explicitly.
24. As a client of Eleanor, I want two archives with the same insert time to be ordered by id in the same direction, so that a page does not swap those two archives between calls.
25. As a client of Eleanor, I want `page=5` to skip the earlier pages, so that I can open a later page without reading the earlier ones.
26. As a client of Eleanor, I want a new archive stored between page 1 and page 2 to be allowed to repeat or skip an archive on page 2, so that page numbers stay page numbers.
27. As a client of Eleanor, I want an owner with no archives to answer 200 with `items` `[]`, `total` 0, and `next` null, so that an empty list is not an error.
28. As a client of Eleanor, I want a missing database file to be that empty list, so that the first call before any POST still answers 200.
29. As a client of Eleanor, I want `page=11` with 98 archives and `size=10` to answer 200 with `items` `[]`, `total` 98, and `next` null, so that a page past the last page is not an error.
30. As a client of Eleanor, I want `size=100` to be accepted when the configured maximum is 100, so that the maximum itself is a legal page.
31. As a client of Eleanor, I want `size=101` to answer 400 `invalid_query` and not a shorter page, so that I learn the cap instead of receiving a silent cut.
32. As a client of Eleanor, I want the maximum to be configuration whose starting value is 100, so that an operator can raise or lower the cap without a code change.
33. As a client of Eleanor, I want `dir=ASC`, `page=0`, `page=-1`, `page=1.5`, `page=abc`, `size=0`, and `size=-1` to answer 400 `invalid_query`, so that a value outside the allowed set is refused.
34. As a client of Eleanor, I want the message of `invalid_query` to be "A parameter has an invalid value.", so that the sentence is fixed.
35. As a client of Eleanor, I want a title such as `Café`, a URL with slashes, and a title with a quote to be valid JSON with unescaped slashes and unescaped Unicode, so that I do not decode the list myself.

### One archive

36. As a client of Eleanor, I want `GET /api/archives/17` to answer 200 `application/json` when 17 is that owner's archive, so that I can read it.
37. As a client of Eleanor, I want the body to contain `id`, `url`, `title`, `excerpt`, `site`, `archived`, and `html`, so that the detail is the list element plus the purified HTML.
38. As a client of Eleanor, I want `html` to be the purified article HTML as a string, so that I can render the archive.
39. As a client of Eleanor, I want `html` to be present even when I only needed the metadata, so that one call returns the archive.
40. As a client of Eleanor, I want a quote or a `<` in `html` to stay valid JSON, so that the body is encoded.
41. As a client of Eleanor, I want an id with no row to answer 404 `archive_not_found`, so that a missing archive is not an unknown path.
42. As a client of Eleanor, I want the message of `archive_not_found` to be "The archive does not exist.", so that the sentence is fixed.
43. As a client of Eleanor, I want a segment that is not a positive integer, such as `abc`, `0`, `-3`, or `1.5`, to answer 400 `invalid_query`, so that a bad id is not a missing archive.
44. As a client of Eleanor, I want a positive integer that the owner does not have, including when the database file is missing, to answer 404 `archive_not_found`, so that absence is one code.
45. As a client of Eleanor, I want `GET /api/archives/` to stay 404 `not_found`, so that a trailing slash is not the list.
46. As a client of Eleanor, I want an unknown path under `/api` to stay 404 `not_found`, so that a wrong path and a missing archive stay different codes.

### The archive command

47. As a client of Eleanor, I want a new archive to answer 201 with `id`, `url`, and `title`, so that I receive the id of the row just written.
48. As a client of Eleanor, I want a second POST of the same URL pointer to answer 200 with the same `id`, `url`, and `title`, so that a repeat returns the existing row.
49. As a client of Eleanor, I want that second POST to keep the same id when the title or the HTML is later replaced, so that the id is the row and not the text.
50. As a client of Eleanor, I want the POST JSON to contain only `id`, `url`, and `title`, so that the excerpt, the site name, the insert time, and the HTML stay out of the command.
51. As a client of Eleanor, I want `id` to be a JSON number, so that I do not parse a string.

### Methods, auth, and errors

52. As a client of Eleanor, I want `HEAD /api/archives` and `HEAD /api/archives/17` to return the GET status and headers and no body, so that I can read the status without the JSON.
53. As a client of Eleanor, I want `PUT` and `DELETE` on both routes to answer 405 `method_not_allowed` with `Allow`, so that I learn the methods.
54. As a client of Eleanor, I want a call without the bearer to answer 401 `unauthenticated` the way the other `/api` routes do, so that the list does not open the API.
55. As a client of Eleanor, I want every failure to stay `application/json` with `code` and `message`, so that I parse one error shape.
56. As a client of Eleanor, I want a 400 and a 404 not to write an error line, so that a bad parameter does not look like a server fault.
57. As a client of Eleanor, I want a request that reached the action to carry `X-Request-Id`, so that I can quote it.
58. As a client of Eleanor, I want only this owner's archives, so that another owner's file is not this list.

### Developer behavior

59. As a developer of Eleanor, I want no new table and no schema version, so that the existing id and insert time are enough.
60. As a developer of Eleanor, I want the list to read the archive row and not the HTML, so that a page does not load every article.
61. As a developer of Eleanor, I want the detail to read the row and the HTML, so that `html` is the stored article.
62. As a developer of Eleanor, I want the store to return the id after a save and on a later read, so that the POST and the list share one id.
63. As a developer of Eleanor, I want the quality checks to pass for `web-app`, so that the routes meet the same bar as the archive command.

## Implementation Decisions

- [adr/0010](../../../adr/0010-archive-list-is-a-page.md), [adr/0011](../../../adr/0011-archive-id-is-public.md), and [adr/0012](../../../adr/0012-archive-detail-is-read-by-id.md) are the decisions this spec adopts. The glossary sentence that an integer id refers to the archive stays.
- The list and the detail are two GET routes under `/api`. The list is the collection. The detail is one id. `POST /api/archives` stays the archive command and gains `id`.
- There is no new table and no schema version. The integer id and the insert time already exist. The list reads the archive metadata and does not read the HTML. The detail reads the metadata and the HTML.
- `sort=archived` maps to the insert time. `dir` is `asc` or `desc`. Rows are ordered by that time and then by the id, both in `dir`. The id is not a sort token.
- An omitted parameter means `sort=archived`, `dir=desc`, `page=1`, and `size=10`. The response repeats those applied values. The client may send the four parameters in any order. An unknown query parameter is ignored.
- `next` is a path beginning with `/api/archives` and the query `sort`, `dir`, `page`, `size` in that order, or null when the page is the last or past the last. `total` is the archive count. `size` in the response is the requested size.
- The maximum `size` is configuration. The starting value is 100. A larger `size` is 400 `invalid_query`. The server does not shrink the page.
- A present `sort` outside the allow-list, a `dir` other than `asc` or `desc`, a `page` below 1, a `size` below 1, and a `page` or `size` that is not an integer are 400 `invalid_query` with the message "A parameter has an invalid value." A page past the last page is 200 with `items` `[]`.
- A list element is `id`, `url`, `title`, `excerpt`, `site`, and `archived`. `excerpt` and `site` are null when absent. `archived` is the stored UTC insert time. The element has no HTML.
- The detail adds `html`, the purified article HTML, always a string. A missing row is 404 `archive_not_found` with the message "The archive does not exist." A path segment that is not a positive integer is 400 `invalid_query`. A trailing slash and an unknown path stay 404 `not_found`.
- The POST success JSON is `id`, `url`, and `title`. A new archive is 201. An archive already stored is 200 with the same id. The excerpt, the site name, the insert time, and the HTML stay out. A later replacement of the title or the HTML keeps the id. A new database file, or a new row after the old row is gone, assigns another id.
- `HEAD` on the list and the detail returns the GET status and headers and an empty body. Other methods stay 405 `method_not_allowed` with `Allow`.
- The bearer and the owner are unchanged. The routes read the authenticated owner's store. Failures under `/api` keep `code` and `message`. A 400 and a 404 do not write an error line. A request that reached the action carries `X-Request-Id`.
- The store returns the id from a save and from a read. A test stand-in that saves archives returns an id too, so the POST contract does not depend on SQLite alone.
- The list document is the document a later search reuses. This feature adds no search parameter and no second list shape.

## Testing Decisions

- A good test observes the status, the content type, the JSON, and the headers. It does not assert SQL, a private call order, or a class name.
- The seam is the kernel test of `/api`, with the bearer and the fetcher double, the same seam as `POST /api/archives`. Archiving URL pointers through that seam prepares the rows. The test proves the list, the detail, the id on POST, `HEAD` with an empty body, each 400, the 404 codes, the empty list, and a page past the last page.
- Two archives stored in one second prove the id tie-break, because the insert time alone does not order them.
- The test sets the page-size maximum when it proves that the maximum is refused and that the maximum itself is accepted.
- `Café`, a slash in a URL, a quote in a title, and a quote in `html` are proved on this seam.
- Unit tests do not boot the kernel. The quality checks for `web-app` pass.
- A browser, a network fetch, and an OpenAPI document are not seams.

## Out of Scope

- A previous-page link, an opaque continuation token, and a cursor that is the URL.
- Reading an archive by URL. `POST /api/archives` remains the way to learn that a URL pointer is already stored.
- Search, fuzzy finding, and any filter other than the page.
- A UUID, a new primary key, and a schema migration.
- HTML pages, caching, and a rendered article.
- OpenAPI.
- A change to the bearer, the owner, or the error document's shape.
- `prev` from the removed backlog note. The note's `page` past the end as 404, `sort=creation`, and `GET /api/archives/list` are not this contract.

## Further Notes

- The backlog file `list.md` is removed. Its draft disagreed with this contract on the path, the sort token, the page past the end, and the wrapper named `cursor`. This spec is the contract.
- Until this feature ships, `GET` and `HEAD` on `/api/archives` stay 405, and the POST stays `url` and `title`. [error-response.md](../../0002-server/specs/error-response.md) and [archive-command.md](../../0002-server/specs/archive-command.md) say so.
