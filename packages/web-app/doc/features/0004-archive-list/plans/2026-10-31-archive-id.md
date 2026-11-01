# Archive id Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/archives` returns the integer row id with `url` and `title`.

**Architecture:** `Archive` carries `?int $id`. `null` means SQLite has not assigned the number. `save` ignores the inbound id, writes by URL, and returns a new `Archive` with the assigned id. `ArchiveSummary` carries that id as `int`. The reply copies `id`, `url`, and `title`.

**Tech Stack:** PHP 8, SQLite through the existing `SqliteArchiveStore`, PHPUnit inside Docker via `./bin/run`.

**Spec:** `packages/web-app/doc/features/0004-archive-list/design/ArchiveId.md` and stories 47–51 of `packages/web-app/doc/features/0004-archive-list/specs/archive-list.md`.

## Global Constraints

- No new table, no schema version, no new dependency.
- `Archive::$id` is `?int` and the last constructor argument. The URL stays first. No default.
- `ArchiveSummary::$id` is `int` and the last constructor argument.
- `ArchiveResponse` constructor order is `id`, `url`, `title`.
- `save` does not read `$archive->id`. The URL is the write key. A second save of the same URL keeps the id.
- `ArchiveSummary::fromArchive` throws `\LogicException` when `id` is `null`.
- `null` is not the signal for a new row. The URL lookup is.
- JSON stays `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- Commands run from `packages/web-app` through `./bin/run`. Do not use host PHP.

## Review Focus

None left uncovered. An inbound id is ignored and a second save keeps the row id (task 2). Each owner starts at 1 (task 2). `fromArchive` rejects `null` (task 2). The JSON id is a number and the keys are `id`, `url`, `title` (task 3). A repeat POST returns that same body and does not fetch again (task 3). A missing bearer stays 401 through the existing `BearerRouteTest`.

---

### Task 1: `Archive` keeps an id

**Files:**
- Create: `packages/web-app/tests/Archive/ArchiveTest.php`
- Modify: `packages/web-app/src/Archive/Archive.php`
- Modify: every existing `new Archive(...)` under `packages/web-app/src` and `packages/web-app/tests` so it passes `null` as the last argument, except the assigned-id case in the new test

**Interfaces:**
- Consumes: nothing
- Produces: `Archive::__construct(string $url, string $title, ?string $excerpt, ?string $siteName, string $html, ?int $id)` with public `?int $id`

- [ ] **Step 1: Write the failing test**

```php
public function test_keeps_null_and_an_assigned_id(): void
{
    $unsaved = new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>', null);
    $saved = new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>', 17);

    self::assertNull($unsaved->id);
    self::assertSame(17, $saved->id);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./bin/run test -- --filter test_keeps_null_and_an_assigned_id`
Expected: FAIL with `ArgumentCountError` (the constructor takes 5 arguments).

- [ ] **Step 3: Add `?int $id` and pass `null` from every current caller**

`fromSuccess` passes `null`. `find` passes `null` until task 2. Do not give the parameter a default.

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./bin/run test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/Archive.php packages/web-app/tests/Archive/ArchiveTest.php packages/web-app/src packages/web-app/tests
git commit -m "$(cat <<'EOF'
feat: carry an optional id on Archive

EOF
)"
```

### Task 2: The store returns the assigned id

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveStore.php`
- Modify: `packages/web-app/src/Archive/ArchiveSummary.php`
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/src/Archive/ArchiveService.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveShelf.php`
- Modify: `packages/web-app/tests/Archive/ArchiveSummaryTest.php`
- Modify: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceTest.php`
- Modify: `packages/web-app/tests/Archive/InMemory/InMemoryArchiveStoreTest.php`
- Modify: `packages/web-app/tests/Api/Response/ArchiveResponseTest.php` (summary now takes an `int` id; the response still ignores it)
- Modify: `packages/web-app/doc/flows/archive-service.md` (save returns the stored archive)

**Interfaces:**
- Consumes: `Archive` with `?int $id` from task 1
- Produces:
  - `ArchiveStore::save(Archive $archive): Archive`
  - `ArchiveSummary::__construct(string $url, string $title, ?string $excerpt, ?string $siteName, int $id)`
  - `ArchiveSummary::fromArchive(Archive $archive): self` copies `id` or throws `\LogicException`
  - `find` and `findSummary` include that id
  - `ArchiveService` builds the `Written` summary from the archive `save` returned

- [ ] **Step 1: Write the failing tests**

In `ArchiveSummaryTest`, the existing `test_copies_the_light_fields_of_an_archive` passes `17` as the archive id so `fromArchive` can copy it. Add:

```php
public function test_copies_the_id(): void
{
    $summary = ArchiveSummary::fromArchive(
        new Archive('https://example.com/a', 'Title', 'Excerpt', 'Site', '<p>body</p>', 17),
    );

    self::assertSame(17, $summary->id);
}

public function test_rejects_an_archive_without_an_id(): void
{
    $this->expectException(\LogicException::class);

    ArchiveSummary::fromArchive(
        new Archive('https://example.com/a', 'Title', null, null, '<p>body</p>', null),
    );
}
```

In `ArchiveStoreContractTest`, on `storeKindProvider`. Use a fresh store and `self::URL` / `self::OTHER_URL`.

```php
public function test_save_returns_the_row_id_and_ignores_the_inbound_id(string $kind): void
{
    $store = $this->factory($kind)->create(new Owner('ada@example.com'));
    $inbound = new Archive(self::URL, 'Title', 'Excerpt', 'Site', '<p>Body</p>', 999);

    $stored = $store->save($inbound);
    $other = $store->save(new Archive(self::OTHER_URL, 'Other', null, null, '<p>Other</p>', null));

    self::assertSame(1, $stored->id);
    self::assertSame(self::URL, $stored->url);
    self::assertNotSame(999, $stored->id);
    self::assertEquals($stored, $store->find(new UrlPointer(self::URL)));
    self::assertEquals(new ArchiveSummary(self::URL, 'Title', 'Excerpt', 'Site', 1), $store->findSummary(new UrlPointer(self::URL)));
    self::assertSame(2, $other->id);
}

public function test_a_second_save_of_the_same_url_keeps_the_id(string $kind): void
{
    $store = $this->factory($kind)->create(new Owner('ada@example.com'));
    $first = $store->save(new Archive(self::URL, 'Old', 'Old excerpt', 'Old site', '<p>Old</p>', null));

    $second = $store->save(new Archive(self::URL, 'New', null, 'New site', '<p>New</p>', null));

    self::assertSame($first->id, $second->id);
    self::assertSame('New', $second->title);
    self::assertEquals($second, $store->find(new UrlPointer(self::URL)));
}

public function test_each_owner_starts_numbering_at_one(string $kind): void
{
    $factory = $this->factory($kind);
    $ada = $factory->create(new Owner('ada@example.com'))
        ->save(new Archive(self::URL, 'Ada', null, null, '<p>A</p>', null));
    $grace = $factory->create(new Owner('grace@example.com'))
        ->save(new Archive(self::URL, 'Grace', null, null, '<p>G</p>', null));

    self::assertSame(1, $ada->id);
    self::assertSame(1, $grace->id);
}
```

Update the existing contract, service, and in-memory assertions that compared the `save` argument with `find`: compare the returned archive instead. Expected summaries and archives that stand for a stored row use id `1` on the first URL. `ArchiveResponseTest` passes `1` into `ArchiveSummary` and still expects a response of url and title only.

In `ArchiveServiceTest::test_first_archive_fetches_saves_and_is_written`, expect summary id `1` and `find` id `1`. `test_second_archive_is_already_stored_and_does_not_fetch` already requires the two summaries to be equal, so the repeated id is that assertion.

- [ ] **Step 2: Run the new store test to verify it fails**

Run: `./bin/run test -- --filter test_save_returns_the_row_id_and_ignores_the_inbound_id`
Expected: FAIL because `save` returns `void`.

- [ ] **Step 3: Return the stored archive from `save`**

`save(Archive $archive): Archive`. SQLite keeps `INSERT … RETURNING id`, casts the id with `(int)`, and returns a new `Archive` with that id and the written fields. The `SELECT`s in `find` and `findSummary` include `id`. The inbound id is not bound.

`InMemoryArchiveShelf::save` returns `Archive`. Number per owner email, starting at 1. A URL that is already stored keeps its id and replaces the other fields. `InMemoryArchiveStore::save` returns that archive. `FakeArchiveStore` does the same for its one array, starting at 1.

`ArchiveService::save` returns that archive. The `Written` result uses `ArchiveSummary::fromArchive` on the return value, not on the fetched archive.

`doc/flows/archive-service.md`: the save box returns the stored archive, and `ArchiveResult Written` is the summary of that return value.

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./bin/run test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive packages/web-app/tests packages/web-app/doc/flows/archive-service.md
git commit -m "$(cat <<'EOF'
feat: return the assigned archive id from the store

EOF
)"
```

### Task 3: The archive command prints the id

**Files:**
- Modify: `packages/web-app/src/Api/Response/ArchiveResponse.php`
- Modify: `packages/web-app/tests/Api/Response/ArchiveResponseTest.php`
- Modify: `packages/web-app/tests/Api/Response/JsonBodySerializerTest.php`
- Modify: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`

**Interfaces:**
- Consumes: `ArchiveSummary::$id` as `int` from task 2
- Produces: `ArchiveResponse::__construct(int $id, string $url, string $title)` and `fromArchiveResult(ArchiveResult $result): self` copying those three fields

- [ ] **Step 1: Write the failing tests**

`ArchiveResponseTest` expects `new ArchiveResponse(17, 'https://example.com/a', 'Title')` from a summary whose id is `17`. Excerpt and site stay off the response.

`JsonBodySerializerTest::test_writes_an_archive_body_without_escaping_slashes_or_unicode` expects:

```text
{"id":17,"url":"https://example.com/a/b","title":"Café"}
```

from `new ArchiveResponse(17, 'https://example.com/a/b', 'Café')`. The quote-and-backslash test constructs `new ArchiveResponse(1, 'https://example.com', $title)` and still asserts the decoded title.

In `CreateArchiveRouteTest`, a first archive on an empty directory is id `1`. Replace the hardcoded success bodies:

- `{"id":1,"url":"https://example.com/a","title":"Title"}`
- `{"id":1,"url":"https://example.com/a","title":""}`
- `{"id":1,"url":"https://example.com/a/b","title":"Café"}`
- the second URL in `test_a_different_url_returns_201_and_fetches_again` is `{"id":2,"url":"https://example.com/b","title":"Second"}`

`test_same_url_again_returns_200_with_the_same_body_and_one_fetch` stays a comparison of the two bodies and `fetchCount()` `1` then `0`. `test_title_with_quote_and_backslash_decodes_to_the_same_title` also asserts `assertSame(1, $decoded['id'])`.

- [ ] **Step 2: Run the response test to verify it fails**

Run: `./bin/run test -- --filter test_copies_url_and_title_and_leaves_the_rest_of_the_result_out`
Expected: FAIL because `ArchiveResponse` has no id.

- [ ] **Step 3: Copy `id`, `url`, and `title` onto `ArchiveResponse`**

Constructor order `id`, `url`, `title`. `fromArchiveResult` reads `$result->summary->id`. Leave excerpt, site name, outcome, and HTML out.

- [ ] **Step 4: Run the route tests, the bearer test, and quality**

Run: `./bin/run test -- --filter 'CreateArchiveRouteTest|ArchiveResponseTest|JsonBodySerializerTest|BearerRouteTest::test_a_missing_header_is_401'`
Expected: PASS

Run: `./bin/run quality`
Expected: exit 0

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Api/Response/ArchiveResponse.php packages/web-app/tests/Api/Response/ArchiveResponseTest.php packages/web-app/tests/Api/Response/JsonBodySerializerTest.php packages/web-app/tests/Integration/CreateArchiveRouteTest.php
git commit -m "$(cat <<'EOF'
feat: return the archive id from the archive command

EOF
)"
```
