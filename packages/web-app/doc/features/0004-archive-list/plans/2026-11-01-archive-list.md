# Archive list Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `GET /api/archives` returns one page of the caller's archives, without HTML, with `next`.

**Architecture:** The action receives a string DTO and the owner's `ArchiveListService`. `ArchivePageQueryFactory` turns the DTO into `ArchivePageQuery` or throws `InvalidArchiveQuery`. The store's `findPage` returns rows and a total. The service mounts `ArchivePage` and logs a failed read. `ArchiveListDocuments` adds `next` with the router. `Reply` only carries status and body.

**Tech Stack:** PHP 8, SQLite through `SqliteArchiveStore`, Symfony routing and the existing `Reply` path, PHPUnit inside Docker via `./bin/run`.

**Spec:** `packages/web-app/doc/features/0004-archive-list/design/ArchiveList.md` and stories 1–35 and 52–63 of `packages/web-app/doc/features/0004-archive-list/specs/archive-list.md`.

## Global Constraints

- No new table, no schema version, no new dependency. Column `created_at` stays. The domain property is `archived`.
- `Archive::$archived` is `?string` and the last constructor argument, after `$id`. No default. The fetch passes `null`.
- `ArchiveSummary::$archived` is `string` and the last constructor argument, after `$id`. No default.
- `ArchiveSummary::fromArchive` throws `\LogicException` when `id` is `null` or `archived` is `null`.
- A second save of the same URL keeps the id and the insert time. `ON CONFLICT` does not change `created_at`.
- `POST /api/archives` JSON stays `id`, `url`, and `title`.
- Defaults are `sort=archived`, `dir=desc`, `page=1`, `size=10`. `sort=archived` is the only sort token. `dir` is `asc` or `desc`.
- An integer string is base-10 digits, no sign, no decimal point, no leading zero, no surrounding space. It fits in a PHP int and is at least 1. `size` is at most the configured maximum. The offset `(page - 1) * size` fits in a PHP int. A larger `size` is not shortened.
- `eleanor.archive_page_max_size` is `100` in `services.yaml` and in `services_test.yaml`. The factory receives that int. A unit test also constructs the factory with `3` to prove the cap is the argument.
- `invalid_query` message is `A parameter has an invalid value.` Status 400. A 400 writes no error line.
- `next` is `/api/archives?sort=archived&dir=desc&page=2&size=10` for that case, or null. Query order is `sort`, `dir`, `page`, `size`. Route name is `list_archives`.
- JSON stays `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- List reads do not join `archive_body`. A missing database file is an empty page, total 0, `next` null, and creates no file.
- Order is `created_at` then `id`, both in `dir`.
- An error created inside `ArchiveListService` logs `archive.list_failed` at warning and is rethrown. `InvalidArchiveQuery` is thrown before the service and is not logged there.
- Commands run from `packages/web-app` through `./bin/run`. Do not use host PHP.

## Review Focus

None left uncovered. A leading zero, `dir=ASC`, and `size` above the maximum are refused without shortening (task 2). Two rows with one insert time stay ordered by id (task 3). A missing file and a page past the end are empty 200s (tasks 3 and 6). `HEAD` is an empty body and the old 405 test is replaced (task 6). A 400 writes no `http.request.failed` line (task 4).

---

### Task 1: Stored rows carry `archived`

**Files:**
- Modify: `packages/web-app/src/Archive/Archive.php`
- Modify: `packages/web-app/src/Archive/ArchiveSummary.php`
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveShelf.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`
- Modify: every `new Archive(` and `new ArchiveSummary(` under `packages/web-app/src` and `packages/web-app/tests`
- Test: `packages/web-app/tests/Archive/ArchiveSummaryTest.php`
- Test: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`

**Interfaces:**
- Consumes: `Archive::__construct(..., ?int $id)` and `ArchiveSummary::__construct(..., int $id)` from YUM-8
- Produces: `Archive::__construct(string $url, string $title, ?string $excerpt, ?string $siteName, string $html, ?int $id, ?string $archived)` and `ArchiveSummary::__construct(string $url, string $title, ?string $excerpt, ?string $siteName, int $id, string $archived)`. `save`, `find`, and `findSummary` set `archived` from the stored `created_at` text. `fromSuccess` passes `null`.

- [ ] **Step 1: Write the failing tests**

`ArchiveSummaryTest`: `fromArchive` copies `archived`. A null `archived` throws `\LogicException`.

`ArchiveStoreContractTest`, both store kinds: the first `save` returns `archived` matching `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/`. A second `save` of the same URL returns that same `archived` and the same id. `find` and `findSummary` return it. `ArchiveResponse` is unchanged.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'test_copies_archived|test_rejects_an_archive_without_archived|test_second_save_keeps_archived'`
Expected: FAIL with `ArgumentCountError` or a missing test method.

- [ ] **Step 3: Add `archived` and return `created_at` from the stores**

SQLite `save` returns the row's `created_at`. The upsert still does not assign `created_at` on conflict. `find` and `findSummary` select `created_at`. The in-memory shelf stamps `gmdate('Y-m-d\TH:i:s\Z')` when the incoming `archived` is null, and keeps the stored value on a second save. Do not add `archived` to `ArchiveResponse`.

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./bin/run test`
Expected: PASS, including `CreateArchiveRouteTest` still expecting `{"id":1,"url":"https://example.com/a","title":"Title"}`.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive packages/web-app/tests packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php
git commit -m "$(cat <<'EOF'
feat: carry the insert time on a stored archive

EOF
)"
```

### Task 2: `ArchivePageQuery` accepts one legal page

**Files:**
- Create: `packages/web-app/src/Archive/ArchiveSort.php`
- Create: `packages/web-app/src/Archive/ArchiveDirection.php`
- Create: `packages/web-app/src/Archive/ArchivePageQuery.php`
- Create: `packages/web-app/src/Archive/InvalidArchiveQuery.php`
- Test: `packages/web-app/tests/Archive/ArchivePageQueryTest.php`

**Interfaces:**
- Consumes: nothing from task 1
- Produces: `enum ArchiveSort: string { case Archived = 'archived'; }`, `enum ArchiveDirection: string { case Asc = 'asc'; case Desc = 'desc'; }`, `ArchivePageQuery::from(?string $sort, ?string $dir, ?string $page, ?string $size, int $maxSize): self` with public `ArchiveSort $sort`, `ArchiveDirection $dir`, `int $page`, `int $size`. `InvalidArchiveQuery` extends `\RuntimeException` and carries no HTTP status.

- [ ] **Step 1: Write the failing test**

```php
public function test_absent_strings_apply_the_defaults(): void
{
    $query = ArchivePageQuery::from(null, null, null, null, 100);

    self::assertSame(ArchiveSort::Archived, $query->sort);
    self::assertSame(ArchiveDirection::Desc, $query->dir);
    self::assertSame(1, $query->page);
    self::assertSame(10, $query->size);
}

#[DataProvider('rejectedProvider')]
public function test_rejected_value_throws(?string $sort, ?string $dir, ?string $page, ?string $size): void
{
    $this->expectException(InvalidArchiveQuery::class);

    ArchivePageQuery::from($sort, $dir, $page, $size, 100);
}
```

`rejectedProvider` yields `created_at`, `ASC`, `0`, `-1`, `+1`, `1.5`, `abc`, `01`, ` 1`, `''`, `size` `0`, `size` `101`, and a `page` whose offset does not fit in a PHP int. One accepted case: `size` `100` with maximum `100` returns size 100. `from` does not return a smaller size.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./bin/run test -- --filter ArchivePageQueryTest`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement `from`**

Null becomes the default. Any other string that is not the token or the integer rule throws `InvalidArchiveQuery`. Do not clamp `size`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./bin/run test -- --filter ArchivePageQueryTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArchiveSort.php packages/web-app/src/Archive/ArchiveDirection.php packages/web-app/src/Archive/ArchivePageQuery.php packages/web-app/src/Archive/InvalidArchiveQuery.php packages/web-app/tests/Archive/ArchivePageQueryTest.php
git commit -m "$(cat <<'EOF'
feat: validate an archive page query

EOF
)"
```

### Task 3: The store returns one page

**Files:**
- Create: `packages/web-app/src/Archive/ArchivePageSlice.php`
- Modify: `packages/web-app/src/Archive/ArchiveStore.php`
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveShelf.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`
- Test: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`

**Interfaces:**
- Consumes: `ArchivePageQuery` from task 2. `archived` on the summary from task 1.
- Produces: `ArchivePageSlice::__construct(array $items, int $total)` where `$items` is `list<ArchiveSummary>`. `ArchiveStore::findPage(ArchivePageQuery $query): ArchivePageSlice`.

- [ ] **Step 1: Write the failing contract tests**

Both kinds. No file: `findPage` of the default query returns `[]` and total 0, and sqlite does not create the file. Three saved archives: `dir=desc` returns the newest first, total 3. `page=2` and `size=2` returns the remaining row, total 3, and that summary has no HTML field. `page=3` and `size=2` returns `[]` and total 3.

Tie-break: pin both rows to `2020-01-01T00:00:00Z`. The in-memory shelf uses a test clock property `now` that `save` reads instead of `gmdate` when set. SQLite runs `UPDATE archive SET created_at = '2020-01-01T00:00:00Z'`. `asc` returns the smaller id first. `desc` returns the larger id first.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'test_missing_file_page_is_empty|test_page_orders_by_insert_time_then_id|test_page_past_the_end_is_empty'`
Expected: FAIL with unknown method `findPage`.

- [ ] **Step 3: Implement `findPage`**

Select `id`, `url`, `title`, `excerpt`, `site_name`, `created_at`. Do not join `archive_body`. Order both columns `ASC` or `DESC` from `ArchiveDirection`, never from the raw query string. Offset is `(page - 1) * size`. `total` is `COUNT(*)` of `archive`. A missing file returns an empty slice and does not call `open`. A read that cannot run throws `ArchiveException` with `ArchiveFailure::Unexpected`. The in-memory shelf sorts by `archived` then `id` and slices. `FakeArchiveStore::findPage` returns a slice the test sets, default `[], 0`.

- [ ] **Step 4: Run the contract test to verify it passes**

Run: `./bin/run test -- --filter ArchiveStoreContractTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArchiveStore.php packages/web-app/src/Archive/ArchivePageSlice.php packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php packages/web-app/tests
git commit -m "$(cat <<'EOF'
feat: read one page of archives from the store

EOF
)"
```

### Task 4: The list service logs a failed read, and `invalid_query` stays outside it

**Files:**
- Create: `packages/web-app/src/Archive/ArchivePage.php`
- Create: `packages/web-app/src/Archive/ArchiveListService.php`
- Create: `packages/web-app/src/Archive/ArchiveListServiceFactory.php`
- Modify: `packages/web-app/src/Api/Error/RequestFailure.php`
- Modify: `packages/web-app/src/Api/Error/ErrorTranslator.php`
- Test: `packages/web-app/tests/Archive/ArchiveListServiceTest.php`
- Test: `packages/web-app/tests/Api/Error/ErrorTranslatorTest.php`
- Test: `packages/web-app/tests/EventSubscriber/TranslateApiExceptionTest.php`

**Interfaces:**
- Consumes: `findPage` and `InvalidArchiveQuery`
- Produces: `ArchivePage::__construct(ArchiveSort $sort, ArchiveDirection $dir, int $page, int $size, array $items, int $total)`. `ArchiveListService::page(ArchivePageQuery $query): ArchivePage`. `ArchiveListServiceFactory::create(Owner $owner): ArchiveListService`. `RequestFailure::InvalidQuery = 'invalid_query'`, status 400, message `A parameter has an invalid value.`

- [ ] **Step 1: Write the failing tests**

Service, with the recording logger from `ArchiveServiceTest`: a store that returns two summaries yields an `ArchivePage` with those items, the query's sort, dir, page, size, and total, and `records` stays `[]`. A store that throws `\RuntimeException` is rethrown and `records` is one warning `archive.list_failed` whose context has `sort`, `dir`, `page`, `size`, and `exception`. A logger that throws still rethrows the store exception. A missing-file slice does not log. Two owners from `ArchiveListServiceFactory` do not share rows: a page saved for Ada is absent from Bea's `findPage`.

`ErrorTranslatorTest`: `new InvalidArchiveQuery()` becomes 400, `invalid_query`, `A parameter has an invalid value.` A generic HTTP 400 still becomes `invalid_body`.

`TranslateApiExceptionTest`: `InvalidArchiveQuery` on `/api/archives` becomes that reply and `getRecords()` is `[]`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'ArchiveListServiceTest|test_translates_invalid_archive_query|test_invalid_query_writes_no_error_line'`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the service and the translator case**

`page` calls `findPage` inside try. On `\Throwable`, log warning `archive.list_failed` and rethrow the same throwable. The log call has its own try, as in `ArchiveService::log`. The factory takes `ArchiveStoreFactory` and the domain logger and is `#[WithMonologChannel('domain')]`, like `ArchiveServiceFactory`. The service is `#[Exclude]`. `ErrorTranslator::translate` matches `InvalidArchiveQuery` before the generic HTTP 400 branch.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./bin/run test -- --filter 'ArchiveListServiceTest|ErrorTranslatorTest|TranslateApiExceptionTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArchivePage.php packages/web-app/src/Archive/ArchiveListService.php packages/web-app/src/Archive/ArchiveListServiceFactory.php packages/web-app/src/Api/Error packages/web-app/tests/Archive/ArchiveListServiceTest.php packages/web-app/tests/Api/Error/ErrorTranslatorTest.php packages/web-app/tests/EventSubscriber/TranslateApiExceptionTest.php
git commit -m "$(cat <<'EOF'
feat: log a failed archive page read

EOF
)"
```

### Task 5: The HTTP document adds `next`

**Files:**
- Create: `packages/web-app/src/Api/Dto/ListArchivesQuery.php`
- Create: `packages/web-app/src/Api/ArchivePageQueryFactory.php`
- Create: `packages/web-app/src/Api/Response/ArchiveListItemResponse.php`
- Create: `packages/web-app/src/Api/Response/ArchiveListResponse.php`
- Create: `packages/web-app/src/Api/Response/ArchiveListDocuments.php`
- Test: `packages/web-app/tests/Api/ArchivePageQueryFactoryTest.php`
- Test: `packages/web-app/tests/Api/Response/ArchiveListDocumentsTest.php`

**Interfaces:**
- Consumes: `ArchivePageQuery::from` and `ArchivePage`
- Produces: `ListArchivesQuery::__construct(?string $sort, ?string $dir, ?string $page, ?string $size)`. `ArchivePageQueryFactory::__construct(int $maxSize)` and `from(ListArchivesQuery $query): ArchivePageQuery`. `ArchiveListItemResponse::__construct(int $id, string $url, string $title, ?string $excerpt, ?string $site, string $archived)`. `ArchiveListResponse::__construct(string $sort, string $dir, int $page, int $size, array $items, int $total, ?string $next)`. `ArchiveListDocuments::__construct(UrlGeneratorInterface $urls)` and `response(ArchivePage $page): ArchiveListResponse`.

- [ ] **Step 1: Write the failing tests**

Factory constructed with `100`: a DTO of nulls returns the defaults. A DTO with `size` `100` returns size 100. A DTO with `size` `101` throws `InvalidArchiveQuery`. Factory constructed with `3`: a DTO with `size` `4` throws, and a DTO with `size` `3` returns size 3.

Documents, with a fake `UrlGeneratorInterface`: total 11, page 1, size 10, sort archived, dir desc calls `generate('list_archives', ['sort' => 'archived', 'dir' => 'desc', 'page' => 2, 'size' => 10], UrlGeneratorInterface::ABSOLUTE_PATH)` and stores that return value in `next`. Total 10 does not call `generate` and `next` is null. `site` on the item is the summary's `siteName`. A null `siteName` stays null.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'ArchivePageQueryFactoryTest|ArchiveListDocumentsTest'`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the factory and the documents**

The factory reads the four DTO strings and calls `ArchivePageQuery::from` with its maximum. `response` sets `next` only when `page * size < total`. The generated parameter array is built in the order `sort`, `dir`, `page`, `size`. Map `siteName` to `site`. Do not read the store.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./bin/run test -- --filter 'ArchivePageQueryFactoryTest|ArchiveListDocumentsTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Api/Dto/ListArchivesQuery.php packages/web-app/src/Api/ArchivePageQueryFactory.php packages/web-app/src/Api/Response/ArchiveListItemResponse.php packages/web-app/src/Api/Response/ArchiveListResponse.php packages/web-app/src/Api/Response/ArchiveListDocuments.php packages/web-app/tests/Api/ArchivePageQueryFactoryTest.php packages/web-app/tests/Api/Response/ArchiveListDocumentsTest.php
git commit -m "$(cat <<'EOF'
feat: build the next archive page link

EOF
)"
```

### Task 6: `GET /api/archives` answers the page

**Files:**
- Create: `packages/web-app/src/Api/Controller/ListArchivesController.php`
- Create: `packages/web-app/src/Api/Resolver/ListArchivesQueryResolver.php`
- Create: `packages/web-app/src/Api/Resolver/ArchiveListServiceResolver.php`
- Modify: `packages/web-app/config/services.yaml`
- Modify: `packages/web-app/config/services_test.yaml`
- Modify: `packages/web-app/tests/Integration/ErrorResponseRouteTest.php`
- Modify: `packages/web-app/tests/Integration/LogApiRequestTest.php`
- Test: `packages/web-app/tests/Api/Resolver/ArchiveListServiceResolverTest.php`
- Test: `packages/web-app/tests/Integration/ListArchivesRouteTest.php`

**Interfaces:**
- Consumes: every type from tasks 4 and 5
- Produces: `ListArchivesController::list(ListArchivesQuery $query, ArchiveListService $archives): Reply` on route `list_archives`, path `/archives`, method `GET`, format `json`. The constructor receives `ArchivePageQueryFactory` and `ArchiveListDocuments`.

- [ ] **Step 1: Write the failing kernel tests**

`services.yaml` and `services_test.yaml` set `eleanor.archive_page_max_size: 100` and bind `$maxSize` of `ArchivePageQueryFactory` to that parameter.

`ListArchivesRouteTest` uses the same bearer, fetcher double, and `ArchiveDirectories::isolate()` as `CreateArchiveRouteTest`. Prepare rows with `POST /api/archives`.

Assert these responses:

- Bare `GET /api/archives` with one archive: 200, `application/json`, body sort `archived`, dir `desc`, page 1, size 10, total 1, `next` null, one item `id` 1, `url` `https://example.com/a`, `title` `Title`, and no `html` key.
- Eleven archives, bare GET: `next` is `/api/archives?sort=archived&dir=desc&page=2&size=10`. `page=2&size=10` returns one item, size 10, total 11, `next` null. `page=3&size=10` returns `items` `[]`, total 11, `next` null.
- No POST and no database file: 200, `items` `[]`, total 0, `next` null.
- `sort=created_at`, `dir=ASC`, `page=0`, `page=+1`, `size=0`, `size=101`: 400 `invalid_query`, message `A parameter has an invalid value.`, header `X-Request-Id` present. `size=100` is 200.
- `?unknown=1` is ignored and returns the default page.
- `page[]=1&page[]=2`: 400 `invalid_query`.
- An item with a null excerpt and a null site name has `"excerpt":null,"site":null`. A title `Café` and a URL with a slash are raw UTF-8 and a raw slash in the body.
- `HEAD /api/archives` with the bearer: the GET status and `content-type`, body `''`, `X-Request-Id` present.
- No bearer: 401 `unauthenticated`.
- `PUT` and `DELETE`: 405 `method_not_allowed`, `Allow` contains `GET`, `HEAD`, and `POST`.

Replace `ErrorResponseRouteTest::test_get_and_head_are_405_with_allow`. `GET` and `HEAD` are no longer 405. Move the `Allow` assertion to `PUT` and `DELETE`.

Replace `LogApiRequestTest::test_head_leaves_the_attribute_absent`. An unauthenticated `HEAD` stays out of the action. An authenticated `HEAD` reaches it. Assert that split instead of status 405.

`ArchiveListServiceResolverTest`: an `ArchiveListService` argument returns the service of the current owner. Another type returns `[]`.

- [ ] **Step 2: Run the kernel test to verify it fails**

Run: `./bin/run test -- --filter ListArchivesRouteTest`
Expected: FAIL with 405 or route not found.

- [ ] **Step 3: Wire the controller**

`ListArchivesQueryResolver` reads only `sort`, `dir`, `page`, and `size`. A missing key becomes null. A string is passed through. An array throws `InvalidArchiveQuery`. Other query keys are ignored. `ArchiveListServiceResolver` copies `ArchiveServiceResolver` and calls `ArchiveListServiceFactory::create`. The action is:

```php
$query = $this->queries->from($query);
$page = $archives->page($query);

return new Reply(200, $this->documents->response($page));
```

The action does not catch. Route methods are `GET`. Symfony answers `HEAD` from that route with an empty body.

- [ ] **Step 4: Run the suite and the quality gate**

Run: `./bin/run test`
Expected: PASS

Run: `./bin/run quality`
Expected: exit 0

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Api/Controller/ListArchivesController.php packages/web-app/src/Api/Resolver packages/web-app/config/services.yaml packages/web-app/config/services_test.yaml packages/web-app/tests/Integration packages/web-app/tests/Api/Resolver/ArchiveListServiceResolverTest.php
git commit -m "$(cat <<'EOF'
feat: list one page of archives

EOF
)"
```
