# Archive list

Module design for YUM-9, one page of an owner's archives. The behavior is [archive-list.md](../specs/archive-list.md) stories 1–34 and 51–62 for the list route. [adr/0010](../../../adr/0010-archive-list-is-a-page.md) is the page. [adr/0011](../../../adr/0011-archive-id-is-public.md) is the id already on the summary. This design adds no glossary term and no schema change.

YUM-8 has already put `?int $id` on `Archive` and `int $id` on `ArchiveSummary`. This design adds the insert time beside that id. [ArchiveId.md](ArchiveId.md) records that follow-up.

## Intent

A client lists one owner's archives, one page at a time, without the purified HTML. The list document is the document a later search will reuse. This ticket adds no search parameter and no second list shape.

## Goals

`GET /api/archives` answers 200 `application/json`. An omitted parameter means `sort=archived`, `dir=desc`, `page=1`, and `size=10`. The body repeats those applied values and adds `items`, `total`, and `next`. Each element is one summary, in property order: `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`. `excerpt` and `siteName` are null when the page had none. `archived` is the stored UTC insert time. `total` is the number of archives. `size` repeats the requested size, including on a short last page. `next` is the path of the following page, or null when this page is the last or past the last.

## Non-goals

The detail route. A previous-page link, an opaque continuation token, and any filter other than the page. Search. A new table or a schema version. HTML in a list element. Publishing the column name `created_at`. Shrinking a `size` above the maximum. A 404 for a page past the last page.

## Anti-goals

A domain type that imports the HTTP DTO or the router. `Reply` building the next path. `ArchiveService` growing a list method. A second row type that copies `ArchiveSummary` and adds only the insert time. A generic HTTP 400, which the translator already maps to `invalid_body`.

## Constraints

`adr/0010` names `siteName` in the summary's property order. `adr/0011` does not change: the JSON contract already names `archived` and the public id. The list reads the archive row and does not read `archive_body`. A missing database file is an empty page, total 0, `next` null. Only the authenticated owner's store is read. A 400 does not write an error line. The existing `Reply` path serializes the body, including `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`.

The maximum `size` is the parameter `eleanor.archive_page_max_size` in `services.yaml`, starting at 100. The container passes it to `ArchivePageQueryFactory`. A test replaces that parameter. It is not an environment variable: the bearer secret is the value that comes from the environment, and this cap is operator configuration like `eleanor.data_dir`.

## Approaches

1. **String DTO, factory from that DTO, one summary type, page split from the link.** Chosen. The action receives the query DTO and the list service of the current owner. `ArchivePageQueryFactory::from` takes the DTO. It holds the configured maximum and returns the applied query, or throws. The store returns the rows and the total. The list service mounts the page. `ArchiveListDocuments`, injected into the controller, adds `next` with the router. `Reply` only carries status and body. One `ArchiveSummary` is the row, so `Archive` and the summary gain `archived` the same way they gained `id`.
2. **A value resolver builds the domain query before the action.** Rejected. The action would not see the original strings, and a framework 400 would become `invalid_body` unless a second error channel learned `invalid_query`. The archive command receives a DTO and builds the domain value in the action.
3. **The action takes `Request`.** Rejected. The action would parse HTTP. Unknown keys are ignored, and `LogApiRequest` already stores the raw query string before the arguments are resolved.
4. **A list row type beside `ArchiveSummary`.** Rejected. The row would copy the summary and add the insert time. The summary is already the light fields of a stored page.

## Types

`Archive` gains `public ?string $archived` as the last constructor argument, after `$id`. There is no default: every caller passes it. Null means the store has not returned the insert time. The fetch passes null. The class stays readonly.

`ArchiveSummary` gains `public string $archived` as the last constructor argument, after `$id`. `fromArchive` copies it. A null id or a null `archived` throws `\LogicException`. That call is a broken caller, not an archive failure. The service calls `fromArchive` only with the archive `save` returned.

`archived` on both types is the text stored in `created_at`, not a new clock. The public JSON name and the domain property use the same word. The column name stays inside the store.

`ListArchivesQuery` is the HTTP DTO. Its properties are `?string $sort`, `?string $dir`, `?string $page`, and `?string $size`. No validation attributes. An absent key stays null. The mapper skips unknown query keys.

`ArchivePageQuery` is the applied query: `ArchiveSort $sort`, `ArchiveDirection $dir`, `int $page`, `int $size`. `ArchiveSort` has the single token `archived`. `ArchiveDirection` is `asc` or `desc`.

`ArchivePageQueryFactory` lives in `Api`. The container injects `eleanor.archive_page_max_size`. `from(ListArchivesQuery $query): ArchivePageQuery` reads the four strings off the DTO and calls `ArchivePageQuery::from(?string $sort, ?string $dir, ?string $page, ?string $size, int $maxSize)`. The controller calls the factory. It does not unpack the DTO. The domain method stays on the strings so `Archive` does not import the HTTP type. A null string is the default. A present value is legal only when it matches the token or the integer rule below.

An integer string is a base-10 digit string with no sign, no decimal point, no leading zero, and no surrounding space. It must fit in a PHP int, be at least 1, and for `size` be at most `$maxSize`. The offset `(page - 1) * size` must also fit in a PHP int. Anything else throws `InvalidArchiveQuery`, including `size` above the maximum. The method does not shorten `size`.

`InvalidArchiveQuery` carries no HTTP status and no client message. The translator owns those.

`ArchivePageSlice` is the store result: a list of `ArchiveSummary` and `int $total`.

`ArchivePage` is the use-case result: the applied `sort`, `dir`, `page`, and `size`, the items, and `total`. It has no `next`.

`ArchiveListResponse` items are the `ArchiveSummary` values on the page. The serializer writes each public property under its own name, in constructor order.

`ArchiveListResponse` is the JSON document: `string $sort`, `string $dir`, `int $page`, `int $size`, `list $items`, `int $total`, `?string $next`. The constructor order is that field order. `sort` and `dir` are the tokens `archived`, `asc`, and `desc`.

## Store

`ArchiveStore` gains `findPage(ArchivePageQuery $query): ArchivePageSlice`. The stand-ins implement it. A missing database file returns an empty list and total 0 and does not create the file. A file that names another owner keeps the store's existing owner check.

The read selects `id`, `url`, `title`, `excerpt`, `site_name`, and `created_at`. It does not join `archive_body`. A read that cannot run throws `ArchiveException` with `Unexpected`. A missing file does not throw. Rows are ordered by `created_at` and then by `id`, both in `dir`. `sort=archived` is the only token, and the store is the place that maps it to `created_at`. The page is 1-based: offset `(page - 1) * size`, limit `size`. `total` counts every archive of this owner, not the page length.

`save` keeps the current upsert. `ON CONFLICT` does not change `created_at`. The archive `save` returns includes that stored `created_at` as `archived`, together with the id. `find` and `findSummary` select `created_at` and set `archived`. A second save of the same URL keeps the same id and the same insert time.

## Services

`ArchiveListService` takes the owner's `ArchiveStore` and the domain logger. It does not fetch or lock. `page(ArchivePageQuery $query): ArchivePage` calls `findPage` and copies the applied query onto the slice. A missing file is an empty page, not a failure. Any throwable that leaves the read is a failure inside the service: the service logs it and rethrows the same throwable, the same way `ArchiveService::save` logs `archive.failed`. The event is `archive.list_failed` at warning. The context is `sort`, `dir`, `page`, `size`, the store path when the store has one, and the exception. A logger that throws does not replace the failure. A successful page writes no domain line. `InvalidArchiveQuery` is thrown by `ArchivePageQueryFactory` before `page` runs. The service does not see it and does not log it.

`ArchiveListServiceFactory` follows `ArchiveServiceFactory`. It holds `ArchiveStoreFactory` and the domain logger. `create(Owner $owner)` returns `new ArchiveListService($stores->create($owner), $logger)`. The service is excluded from the container, like `ArchiveService`. The controller does not build the list service. `OwnerScopedServiceResolver` returns `$lists->create($owner)` for an `ArchiveListService` argument and `$archives->create($owner)` for an `ArchiveService` argument.

`ArchiveListDocuments` is an HTTP service the container injects into the list controller. Its constructor takes the router. `response(ArchivePage $page): ArchiveListResponse` computes `next` and builds the JSON document. The action does not call the router and does not hide that work in a private method. When `page * size < total` it asks the router for the path of route `list_archives` with `sort`, `dir`, `page + 1`, and `size` in that order, as an absolute path. Otherwise `next` is null. That covers an empty owner, a missing file, the last page, and a page past the last page.

## Data flow

1. Bearer, then `LogApiRequest` on `kernel.controller`. The raw query string is already on the start line.
2. The container has already built `ArchivePageQueryFactory` with the maximum, and `ArchiveListDocuments` with the router. Resolvers supply `ListArchivesQuery` and `ArchiveListService` for the current owner.
3. The controller calls `ArchivePageQueryFactory::from` with the DTO. `InvalidArchiveQuery` leaves the action. The controller does not catch it.
4. `ArchiveListService::page` calls `findPage` and returns `ArchivePage`. Any throwable from that read logs `archive.list_failed` and leaves the service unchanged.
5. The controller calls `ArchiveListDocuments::response` with that page.
6. The action returns `Reply` with status 200 and that response. `WriteReply` serializes it.

A page past the last page is still step 4 with an empty item list and the real total, then step 5 with `next` null, then 200.

## Errors

`RequestFailure` gains `InvalidQuery = 'invalid_query'`, status 400, message "A parameter has an invalid value." `ErrorTranslator` maps `InvalidArchiveQuery` to that case. It does not map this failure through the generic HTTP 400 branch, which stays `invalid_body` or `invalid_json`.

`TranslateApiException` already skips an error line below status 500. This 400 adds no log call.

`HEAD /api/archives` returns the GET status and headers and an empty body. `PUT` and `DELETE` stay 405 `method_not_allowed` with `Allow`. A missing bearer stays 401 `unauthenticated`. A request that reached `kernel.controller` still carries `X-Request-Id` when the factory throws, because that subscriber runs before the arguments are resolved.

## Left to the builder

The mapper attribute that fills `ListArchivesQuery` and skips unknown keys. The route's method list so that `HEAD` has an empty body and `Allow` names the methods the spec requires. How a test stand-in picks `archived`, as long as a second save of the same URL keeps it, two rows may share one timestamp, and the text is UTC `Y-m-d\TH:i:s\Z`. The SQL text. The assertions in the existing kernel seam.

A repeated query key is `invalid_query` when the mapper does not yield a single string.

## Out of scope

`GET /api/archives/{id}`. Changing the POST JSON. `prev`. A 404 for a page past the last page. OpenAPI. A change to the bearer or the owner file name.
