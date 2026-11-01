# Archive detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `GET /api/archives/{id}` returns one archive as metadata, as metadata plus HTML, or as the stored HTML with no template.

**Architecture:** `ArchiveId` accepts the path segment or throws `InvalidArchiveQuery`. `ArchiveReadService` (the renamed list service) reads a summary without `archive_body`, a full `Archive`, or the HTML string. The JSON action returns `Reply`. The HTML action returns a framework response. A null read throws `MissingArchive`, which the translator maps to `archive_not_found`.

**Tech Stack:** PHP 8, SQLite through `SqliteArchiveStore`, Symfony routing and the existing `Reply` path, PHPUnit inside Docker via `./bin/run`.

**Spec:** `packages/web-app/doc/features/0004-archive-list/design/ArchiveDetail.md` and stories 35–45 and 51–62 of `packages/web-app/doc/features/0004-archive-list/specs/archive-list.md`.

## Global Constraints

- No new table, no schema version, no new dependency. HTML stays in `archive_body.html`.
- The id rule matches `ArchivePageQuery`: base-10 digits, no sign, no decimal point, no leading zero, no surrounding space, at least 1, and it fits in a PHP int. `ArchiveId::from` copies that regex. Do not open the private method on `ArchivePageQuery`.
- Bare `GET /api/archives/{id}` is `application/json` with keys `url`, `title`, `excerpt`, `siteName`, `id`, `archived`, in that order, and no `html` key.
- `includeContent=true` adds `html` last. Any other present value is 400 `invalid_query`, message `A parameter has an invalid value.`
- `GET /api/archives/{id}.html` is status 200, `Content-Type: text/html; charset=UTF-8`, and the stored HTML alone. A query string on that path is ignored.
- A missing row, including a missing database file, is 404 `archive_not_found`, message `The archive does not exist.` The file is not created. The same code and message apply to the metadata route, `includeContent=true`, and `.html`.
- A segment that is not a positive integer, including `abc.html`, is 400 `invalid_query`. `GET /api/archives/`, `GET /api/archives/17.json`, and an unknown path stay 404 `not_found`, message `The path does not exist.`
- Failures stay `application/json` with `code` and `message`, including a failure of `.html`. A 400 and a 404 write no error line.
- `HEAD` returns the GET status and headers and an empty body. `PUT` and `DELETE` are 405 `method_not_allowed` with `Allow` naming `GET` and `HEAD` only.
- A missing bearer is 401 `unauthenticated`, message `The request is not authenticated.`
- JSON options stay `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- Do not serialize `Archive` as the full document. Do not throw `NotFoundHttpException` for a missing row.
- `page` keeps the event `archive.list_failed`. A failed by-id read logs `archive.detail_failed` at warning and is rethrown. Null is not logged. `InvalidArchiveQuery` and `MissingArchive` are thrown outside the service.
- Commands run from `packages/web-app` through `./bin/run`. Do not use host PHP.

## Review Focus

None left uncovered. `01`, `1.5`, and a value above `PHP_INT_MAX` are 400, while `17.json` is 404 (tasks 1 and 6). A missing file is 404 and creates nothing (tasks 2 and 6). `includeContent=false` and a repeated key are 400, and a query on `.html` is ignored (tasks 4 and 6). A quote and a `<` survive JSON, and a failed `.html` call is still JSON (task 6). A missing archive writes no error line (task 5). `HEAD` is an empty body, and `Allow` on the detail paths does not name `POST` (task 6).

---

### Task 1: `ArchiveId` accepts one positive integer

**Files:**
- Create: `packages/web-app/src/Archive/ArchiveId.php`
- Test: `packages/web-app/tests/Archive/ArchiveIdTest.php`

**Interfaces:**
- Consumes: `InvalidArchiveQuery`
- Produces: `final readonly class ArchiveId` with `public int $value` and `from(string $segment): self`. A rejected segment throws `InvalidArchiveQuery`.

- [ ] **Step 1: Write the failing test**

`from('17')` returns an id whose `value` is `17`. A data provider rejects `abc`, `0`, `-3`, `1.5`, `01`, `+1`, ` 1`, `''`, and the digit string of `PHP_INT_MAX` plus one. Each rejection expects `InvalidArchiveQuery`. `from((string) PHP_INT_MAX)` returns that int.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./bin/run test -- --filter ArchiveIdTest`
Expected: FAIL with a missing class.

- [ ] **Step 3: Implement `ArchiveId::from`**

Copy the integer rule from `ArchivePageQuery`: `/^[1-9][0-9]*\z/`, then reject a digit string longer than `(string) PHP_INT_MAX` or greater at the same length. Store the int on `value`. Mark the class `#[Exclude]`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./bin/run test -- --filter ArchiveIdTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArchiveId.php packages/web-app/tests/Archive/ArchiveIdTest.php
git commit -m "$(cat <<'EOF'
feat: accept an archive id only as a positive integer

EOF
)"
```

### Task 2: The store reads one archive by id

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveStore.php`
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveShelf.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`
- Modify: `packages/web-app/tests/Archive/ArchiveListServiceTest.php` (`ThrowingPageStore` only, so the suite still implements the interface)
- Test: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`

**Interfaces:**
- Consumes: `ArchiveId` from task 1
- Produces: `findSummaryById(ArchiveId $id): ?ArchiveSummary`, `findById(ArchiveId $id): ?Archive`, `findHtmlById(ArchiveId $id): ?string` on `ArchiveStore` and every implementor. A missing id or a missing database file is null and creates no file.

- [ ] **Step 1: Write the failing contract tests**

`test_unknown_id_is_absent`, both store kinds: an unknown id is null from all three methods. `test_finds_one_archive_by_id`: after `save` of URL `https://example.com/a`, title `Title`, excerpt `Excerpt`, site `Site`, HTML `<p>Body</p>`, id `1` is found by `findSummaryById` as those light fields. `findById` returns the same fields plus HTML `<p>Body</p>`, with non-null `id` and `archived`. `findHtmlById` returns `<p>Body</p>`. `test_empty_html_is_stored_text`: a saved archive whose HTML is `''` returns `''` from `findHtmlById` and from `findById`. `test_missing_file_id_is_absent`: a SQLite store whose file does not exist returns null from all three and does not create the file.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'test_unknown_id_is_absent|test_finds_one_archive_by_id|test_empty_html_is_stored_text|test_missing_file_id_is_absent'`
Expected: FAIL with an unknown method.

- [ ] **Step 3: Add the three reads**

`findSummaryById` selects `id`, `url`, `title`, `excerpt`, `site_name`, `created_at` from `archive` and does not join `archive_body`. `findById` joins `archive_body` and returns an `Archive` with that HTML, id, and `created_at` as `archived`. `findHtmlById` selects `html` for that id. SQLite uses the same owner check as `findPage`. A missing file returns null before opening. The in-memory shelf and `FakeArchiveStore` answer from the rows they already keep. `ThrowingPageStore` implements the three methods by throwing the same failure it uses for `findPage`.

- [ ] **Step 4: Run the contract tests to verify they pass**

Run: `./bin/run test -- --filter ArchiveStoreContractTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArchiveStore.php packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php packages/web-app/tests
git commit -m "$(cat <<'EOF'
feat: read one stored archive by its id

EOF
)"
```

### Task 3: `ArchiveReadService` reads a page or one archive

**Files:**
- Create: `packages/web-app/src/Archive/ArchiveReadService.php` (rename of `ArchiveListService.php`)
- Create: `packages/web-app/src/Archive/ArchiveReadServiceFactory.php` (rename of `ArchiveListServiceFactory.php`)
- Modify: `packages/web-app/src/Api/Resolver/OwnerScopedServiceResolver.php`
- Modify: `packages/web-app/src/Api/Controller/ListArchivesController.php`
- Modify: every remaining `ArchiveListService` reference under `packages/web-app`
- Test: `packages/web-app/tests/Archive/ArchiveReadServiceTest.php` (rename of `ArchiveListServiceTest.php`)
- Test: `packages/web-app/tests/Api/Resolver/OwnerScopedServiceResolverTest.php`

**Interfaces:**
- Consumes: the three store methods from task 2. `page(ArchivePageQuery $query): ArchivePage` stays.
- Produces: `ArchiveReadService::summary(ArchiveId $id): ?ArchiveSummary`, `read(ArchiveId $id): ?Archive`, `html(ArchiveId $id): ?string`. `ArchiveReadServiceFactory::create(Owner $owner): ArchiveReadService`. The resolver matches `ArchiveReadService::class`.

- [ ] **Step 1: Write the failing service tests**

Rename the existing page tests with the class. Add: a summary hit returns the store summary, a miss returns null and writes no log, a thrown read logs `archive.detail_failed` at warning with `id`, `view` `summary`, the store path, and the exception, then rethrows. The same log shape uses `view` `read` and `view` `html`. A logger that throws still rethrows the read failure. The existing `archive.list_failed` page test still passes.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'ArchiveReadServiceTest|OwnerScopedServiceResolverTest'`
Expected: FAIL with a missing class or a missing method.

- [ ] **Step 3: Rename the service and add the three reads**

Move the class and the factory to the new names. `summary`, `read`, and `html` call the matching store method. Null returns null. A throwable logs `archive.detail_failed` and is rethrown. A logger failure is swallowed. `page` still logs `archive.list_failed`. Point the resolver, `ListArchivesController`, and the tests at `ArchiveReadService`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./bin/run test -- --filter 'ArchiveReadServiceTest|OwnerScopedServiceResolverTest|ListArchivesRouteTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src packages/web-app/tests
git commit -m "$(cat <<'EOF'
feat: read one archive from the list service

EOF
)"
```

### Task 4: The detail document and the content flag

**Files:**
- Create: `packages/web-app/src/Api/Dto/ArchiveReadQuery.php`
- Create: `packages/web-app/src/Api/Resolver/ArchiveReadQueryResolver.php`
- Create: `packages/web-app/src/Api/Response/ArchiveDetailResponse.php`
- Test: `packages/web-app/tests/Api/Resolver/ArchiveReadQueryResolverTest.php`
- Test: `packages/web-app/tests/Api/Response/ArchiveDetailResponseTest.php`

**Interfaces:**
- Consumes: `InvalidArchiveQuery`, `Archive`
- Produces: `ArchiveReadQuery` with `public ?string $includeContent`. `ArchiveReadQueryResolver::resolve` returns that DTO for an argument of that type. `ArchiveDetailResponse::fromArchive(Archive $archive): self` with constructor order `url`, `title`, `excerpt`, `siteName`, `id`, `archived`, `html`.

- [ ] **Step 1: Write the failing tests**

The resolver maps an absent key to null, the string `true` to `true`, and ignores `sort`. An array value, from a repeated key, throws `InvalidArchiveQuery`. `fromArchive` copies a stored archive into those seven properties, with `html` last. A null `id` or a null `archived` throws `\LogicException`. `JsonBodySerializer` writes `{"url":"https://example.com/a","title":"Title","excerpt":null,"siteName":null,"id":1,"archived":"2026-11-01T00:00:00Z","html":"<p>say \"hi\" <b>x</b></p>"}` for that document, and `json_decode` returns the original HTML.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter 'ArchiveReadQueryResolverTest|ArchiveDetailResponseTest'`
Expected: FAIL with a missing class.

- [ ] **Step 3: Add the DTO, the resolver, and the document**

Follow `ListArchivesQueryResolver`: a missing key stays null, a non-string throws `InvalidArchiveQuery`, other keys are ignored. `fromArchive` copies the seven fields and throws `\LogicException` when `id` or `archived` is null. Both new classes that are not services get `#[Exclude]`. The resolver does not.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./bin/run test -- --filter 'ArchiveReadQueryResolverTest|ArchiveDetailResponseTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Api packages/web-app/tests/Api
git commit -m "$(cat <<'EOF'
feat: shape one archive as metadata plus optional HTML

EOF
)"
```

### Task 5: A missing archive is `archive_not_found`

**Files:**
- Create: `packages/web-app/src/Archive/MissingArchive.php`
- Modify: `packages/web-app/src/Api/Error/RequestFailure.php`
- Modify: `packages/web-app/src/Api/Error/ErrorTranslator.php`
- Test: `packages/web-app/tests/Api/Error/ErrorTranslatorTest.php`
- Test: `packages/web-app/tests/EventSubscriber/TranslateApiExceptionTest.php`

**Interfaces:**
- Consumes: `ErrorTranslator::translate(\Throwable $error): Reply`
- Produces: `MissingArchive extends \RuntimeException`. `RequestFailure::ArchiveNotFound = 'archive_not_found'`, status 404, message `The archive does not exist.` `ErrorTranslator` maps `MissingArchive` to that case.

- [ ] **Step 1: Write the failing test**

`translate(new MissingArchive())` returns status 404, code `archive_not_found`, message `The archive does not exist.` `translate(new NotFoundHttpException())` still returns code `not_found`. `TranslateApiExceptionTest`: a `MissingArchive` on `/api/archives/1` writes no `http.request.failed` record and the response body is `{"code":"archive_not_found","message":"The archive does not exist."}`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./bin/run test -- --filter 'test_translates_missing_archive|test_translates_the_throwable'`
Expected: FAIL with a missing class, or the missing archive still mapped to `unexpected`.

- [ ] **Step 3: Map `MissingArchive`**

Add the enum case beside `NotFound`, with status 404 and that message. In `requestFailure`, match `MissingArchive` before the generic throwable becomes `Unexpected`. `MissingArchive` carries no client sentence. Mark it `#[Exclude]`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./bin/run test -- --filter 'ErrorTranslatorTest|test_missing_archive_writes_no_error_line'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/MissingArchive.php packages/web-app/src/Api/Error packages/web-app/tests/Api/Error/ErrorTranslatorTest.php packages/web-app/tests/EventSubscriber/TranslateApiExceptionTest.php
git commit -m "$(cat <<'EOF'
feat: answer a missing archive with its own not-found code

EOF
)"
```

### Task 6: The detail routes

**Files:**
- Create: `packages/web-app/src/Api/Controller/GetArchiveController.php`
- Test: `packages/web-app/tests/Integration/GetArchiveRouteTest.php`

**Interfaces:**
- Consumes: `ArchiveId::from`, `ArchiveReadQuery`, `ArchiveReadService::summary`, `read`, and `html`, `ArchiveDetailResponse::fromArchive`, `MissingArchive`, `Reply`
- Produces: route `read_archive` on `/archives/{id}` for `GET` and `HEAD`, format `json`, requirement `id` = `(?!.*\.(?:json|html)$)[^/]+`. Route `read_archive_html` on `/archives/{id}.html` for `GET` and `HEAD`, requirement `id` = `[^/.]+`. `read` returns `Reply`. `html` returns `Response`.

- [ ] **Step 1: Write the failing kernel tests**

Follow `ListArchivesRouteTest`: isolate directories, fake fetcher, POST the URL, then GET. Archive one page whose HTML is `say "hi" <b>x</b>` and whose title is `Title`.

`GET /api/archives/1` is 200 `application/json`. Keys are `url`, `title`, `excerpt`, `siteName`, `id`, `archived` in that order. There is no `html` key. `id` is `1`. The matched route is `read_archive`. The response carries `X-Request-Id`.

`GET /api/archives/1?includeContent=true` is 200. Keys add `html` last. `json_decode` returns the HTML `say "hi" <b>x</b>`. `?includeContent=false`, `?includeContent=1`, and `?includeContent=TRUE` are 400 `invalid_query` with message `A parameter has an invalid value.` A repeated `includeContent` is the same 400. `?sort=no` on the bare path still returns the metadata document.

`GET /api/archives/1.html` is 200, header `content-type` equals `text/html; charset=UTF-8`, and the body is exactly `say "hi" <b>x</b>`. `GET /api/archives/1.html?includeContent=false` is that same body. The matched route is `read_archive_html`.

`GET /api/archives/999`, `GET /api/archives/999?includeContent=true`, and `GET /api/archives/999.html` are 404 `archive_not_found`, message `The archive does not exist.`, content type `application/json`. The same 404 is returned when the database file is missing, and the file is not created.

`GET /api/archives/abc`, `/0`, `/-3`, `/1.5`, `/01`, and `/abc.html` are 400 `invalid_query`. The `abc` response carries `X-Request-Id`. `GET /api/archives/`, `GET /api/archives/17.json`, and `GET /api/no-such` are 404 `not_found`, message `The path does not exist.`

`HEAD /api/archives/1` and `HEAD /api/archives/1.html` return the GET status and content type and an empty body. `PUT` and `DELETE` on both paths are 405 `method_not_allowed`. `Allow` contains `GET` and `HEAD` and does not contain `POST`. A call without the bearer is 401 `unauthenticated`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./bin/run test -- --filter GetArchiveRouteTest`
Expected: FAIL with 404 `not_found` on `/api/archives/1`.

- [ ] **Step 3: Add `GetArchiveController`**

`read(string $id, ArchiveReadQuery $query, ArchiveReadService $archives): Reply` builds `ArchiveId`, throws `InvalidArchiveQuery` unless `includeContent` is null or `true`, calls `summary` or `read`, throws `MissingArchive` on null, and returns `Reply` 200 with the summary or `ArchiveDetailResponse::fromArchive`. `html(string $id, ArchiveReadService $archives): Response` builds the id, calls `html`, throws `MissingArchive` on null, and returns status 200 with `Content-Type: text/html; charset=UTF-8` and that string. Declare `GET` and `HEAD` only. Do not branch on `HEAD`. Do not catch.

- [ ] **Step 4: Run the route tests to verify they pass**

Run: `./bin/run test -- --filter GetArchiveRouteTest`
Expected: PASS

- [ ] **Step 5: Run the suite and the quality checks**

Run: `./bin/run test`
Expected: PASS

Run: `./bin/run quality`
Expected: exit 0

- [ ] **Step 6: Commit**

```bash
git add packages/web-app/src/Api/Controller/GetArchiveController.php packages/web-app/tests/Integration/GetArchiveRouteTest.php
git commit -m "$(cat <<'EOF'
feat: read one archive as JSON or as stored HTML

EOF
)"
```
