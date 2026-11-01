# Archive detail

Module design for YUM-10, one owner's archive read by id. The behavior is [archive-list.md](../specs/archive-list.md) stories 35–45 and 51–62 for the detail routes. [adr/0012](../../../adr/0012-archive-detail-is-read-by-id.md) is the three representations. This design adds no glossary term and no schema change.

## Intent

A client reads one archive by the integer id. The bare GET is the metadata. `includeContent=true` adds the purified HTML in the same JSON document. `{id}.html` returns that HTML with no template. The metadata read does not load the body.

## Goals

`GET /api/archives/{id}` answers 200 `application/json` with `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`, in that order, and no `html` key. `GET /api/archives/{id}?includeContent=true` answers 200 `application/json` with those fields and `html` last. `html` is the stored purified HTML, always a string. `GET /api/archives/{id}.html` answers 200 `text/html; charset=UTF-8` and the stored HTML alone.

An omitted `includeContent` is the metadata. Any other value is 400 `invalid_query`. A missing row, including a missing database file, is 404 `archive_not_found` on all three. A segment that is not a positive integer is 400 `invalid_query`. `GET /api/archives/`, `GET /api/archives/17.json`, and an unknown path stay 404 `not_found`.

`HEAD` on both detail paths returns the GET status and headers and an empty body. `PUT` and `DELETE` are 405 `method_not_allowed` with `Allow`. A missing bearer is 401 `unauthenticated`. Every failure is `application/json` with `code` and `message`. A 400 and a 404 write no error line.

## Non-goals

A page template, a layout, caching, and a rendered article. A second service beside the list reader. A new table or a schema version. Reading by URL. Changing the POST JSON. OpenAPI.

## Anti-goals

Serializing `Archive` as the full JSON document. Its constructor puts `html` before `id`, and the spec puts `html` last. A route requirement that rejects `1.5` before the action, which would answer 404 `not_found` instead of 400 `invalid_query`. Loading `archive_body` for the bare GET. A static HTML file beside the SQLite database. `NotFoundHttpException` for a missing row, which the translator already maps to `not_found`.

## Constraints

`adr/0012` names the three representations and keeps read-by-id. `adr/0008` keeps the body in `archive_body`. The list's integer rule is the id rule: base-10 digits, no sign, no decimal point, no leading zero, at least 1, and it fits in a PHP int. `ArchivePageQuery` already enforces that rule in private.

`Reply` carries an object. `ReplyResponder` always writes JSON. `WriteReply` leaves any other controller result alone, so the HTML action can return a framework response. Failures still go through `TranslateApiException`, which writes JSON and skips an error line below status 500.

Only the authenticated owner's store is read. A file that names another owner stays the store's existing refusal. It is not `archive_not_found`.

## Approaches

1. **Two reads on the renamed list service.** Chosen. `ArchiveListService` becomes `ArchiveReadService` and keeps `page`. It gains a summary read, a full read, and an HTML read. The summary selects `archive` and does not join `archive_body`. The full read joins the body. The HTML read selects `html`. The JSON action returns `Reply`. The HTML action returns the stored string. One owner resolver still builds the service.
2. **One read of `Archive`, and the controller drops `html`.** Rejected. The bare GET would pay for the body. The list exists so a metadata read does not.
3. **A new service only for the detail.** Rejected. It would duplicate the store, the owner, and the logger for reads the list service already owns.

## Types

`ArchiveId` is the domain id. `from(string $segment): self` applies the integer rule above and throws `InvalidArchiveQuery`. The value is a positive PHP int. The route argument stays a string. The action calls `from`. There is no default and no framework validation attribute.

`MissingArchive` is an empty exception. It carries no HTTP status and no client sentence. The service does not throw it. A null read is absence. The action throws it when the read returns null.

`ArchiveReadQuery` is the HTTP DTO for the JSON action. Its only property is `?string $includeContent`. No validation attributes. An absent key stays null. The HTML action does not take this DTO, so a query string on `.html` is never read.

`ArchiveDetailResponse` is the JSON document with HTML. The constructor order is `url`, `title`, `excerpt`, `siteName`, `id`, `archived`, `html`. `fromArchive(Archive $archive): self` copies those fields. A null id or a null `archived` throws `\LogicException`. That call is a broken caller. The action calls it only with the archive the full read returned. The bare GET returns the `ArchiveSummary` itself. The serializer writes each public property under its own name, in constructor order, which is already the list-element order.

## Store

`ArchiveStore` gains three methods. The stand-ins implement them. A missing database file returns null and does not create the file. A file that names another owner keeps the store's existing owner check.

`findSummaryById(ArchiveId $id): ?ArchiveSummary` selects `id`, `url`, `title`, `excerpt`, `site_name`, and `created_at` from `archive`. It does not join `archive_body`.

`findById(ArchiveId $id): ?Archive` selects those columns and `html` through the join on `archive_body`. The returned `Archive` has a non-null id and a non-null `archived`.

`findHtmlById(ArchiveId $id): ?string` selects `html` from `archive_body` for that id. The string is the stored fragment, including when it is empty.

A read that cannot run throws `ArchiveException` with `Unexpected`. A missing file does not throw. No method creates the database.

## Services

`ArchiveReadService` is the renamed `ArchiveListService`. `page` stays, including the event `archive.list_failed`. The class does not fetch or lock.

`summary(ArchiveId $id): ?ArchiveSummary` calls `findSummaryById`. `read(ArchiveId $id): ?Archive` calls `findById`. `html(ArchiveId $id): ?string` calls `findHtmlById`. Null is a miss and is not logged. Any throwable that leaves a by-id read is logged and rethrown. The event is `archive.detail_failed` at warning. The context is the id, the view `summary`, `read`, or `html`, the store path when the store has one, and the exception. A logger that throws does not replace the failure. A successful read writes no domain line.

`InvalidArchiveQuery` and `MissingArchive` are thrown outside these methods. The service does not see them.

`ArchiveReadServiceFactory` is the renamed factory. `create(Owner $owner)` returns `new ArchiveReadService(...)`. The service stays excluded from the container. `OwnerScopedServiceResolver` matches `ArchiveReadService` instead of `ArchiveListService`. `ListArchivesController` takes the renamed type. The call sites in tests follow the rename.

## HTTP

`GetArchiveController` has two actions.

`read` is `GET` and `HEAD` on `/archives/{id}`, route name `read_archive`, format `json`. The segment requirement is a non-empty path segment that does not end in `.json` or `.html`. `1.5`, `abc`, `0`, `-3`, and `01` still match, and `ArchiveId::from` rejects them. `17.json` does not match, so it stays 404 `not_found`. The action receives `string $id`, `ArchiveReadQuery`, and `ArchiveReadService`. It builds the id, then reads `includeContent`. Null calls `summary` and returns `Reply` 200 with that summary. The string `true` calls `read` and returns `Reply` 200 with `ArchiveDetailResponse`. Any other string throws `InvalidArchiveQuery`. A null summary or a null archive throws `MissingArchive`. The action does not catch.

`html` is `GET` and `HEAD` on `/archives/{id}.html`, route name `read_archive_html`. The id requirement excludes a dot, so the suffix is not part of the id. The action receives `string $id` and `ArchiveReadService`. It builds the id, calls `html`, and returns a framework response with status 200, `Content-Type: text/html; charset=UTF-8`, and that string as the body. A null HTML throws `MissingArchive`. The action does not return `Reply`.

The resolver for `ArchiveReadQuery` follows `ListArchivesQueryResolver`. A missing `includeContent` stays null. Any other query key is ignored. An array value throws `InvalidArchiveQuery`.

`HEAD` is declared beside `GET` and the action does not branch on it. The kernel clears the body, as it does for the list. `Allow` on 405 is the methods declared on that route.

## Data flow

1. Bearer, then `LogApiRequest` on `kernel.controller`.
2. The resolver supplies `ArchiveReadService` for the current owner. The JSON action also receives `ArchiveReadQuery`.
3. The action calls `ArchiveId::from`. `InvalidArchiveQuery` leaves the action.
4. The JSON action accepts only null or `true`. Anything else throws `InvalidArchiveQuery` before a read.
5. The service reads. Null throws `MissingArchive` in the action. A throwable from the store logs `archive.detail_failed` and leaves the service.
6. The JSON action returns `Reply` 200. `WriteReply` serializes it. The HTML action returns the framework response. `WriteReply` leaves it alone.

## Errors

`RequestFailure` gains `ArchiveNotFound = 'archive_not_found'`, status 404, message "The archive does not exist." `ErrorTranslator` maps `MissingArchive` to that case. It does not use the `NotFoundHttpException` branch, which stays `not_found`.

`InvalidArchiveQuery` stays 400 `invalid_query`. `TranslateApiException` already skips an error line below status 500. Neither new failure adds a log call.

A failure of `.html` is still that JSON reply. The HTML content type is only the 200 body.

## Left to the builder

The SQL text. How a test stand-in stores the three reads, as long as a missing id is null and the summary read does not need the HTML. Whether `ArchiveId` copies the integer regex or `ArchivePageQuery` and `ArchiveId` share one helper. The assertions on the existing kernel seam. The route score that makes `{id}.html` win over `{id}` for a path that ends in `.html`, given the requirements above.

A repeated `includeContent` is `invalid_query`, because the mapper rejects an array.

## Out of scope

A template around the fragment. Caching. Search. A change to the list document, the POST JSON, the bearer, or the owner file. OpenAPI.
