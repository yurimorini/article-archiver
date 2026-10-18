# URL Pointer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The archive command names a page with a `UrlPointer`, so leading and trailing NUL bytes are removed in one place and every lookup, fetch, and lock uses that value.

**Architecture:** `UrlPointer` stores `trim($url, "\0")` and exposes it only through `toString()`. `archive`, `exclusive`, `fetch`, `find`, and `findSummary` take that type. `save` still takes an `Archive`. SQL, the flock key, the in-memory shelf, and the article-reader library receive `toString()`. The controller builds the pointer from the body string. The body DTO stays a string.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 11, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/UrlPointer.md`

## Global Constraints

- The constructor stores `trim($url, "\0")`. A NUL in the middle stays. Whitespace stays. The constructor does not throw. An empty string after the trim is a pointer.
- `toString(): string` is the only way out. No `__toString`. The raw input is not kept. No public property for the stored string.
- `https://example.com/a` and `https://example.com/a/` stay two pointers. `\0https://example.com/a\0` and `https://example.com/a` are one pointer.
- `ArchiveService::archive(UrlPointer $url)` does not trim. The lock, the fetch, and `findSummary` receive that same pointer.
- `ArchiveLock::exclusive(UrlPointer $url, callable $action)`. The callable takes no arguments. The flock key is the owner email, a NUL byte, and `$url->toString()`.
- `ArticleFetcher::fetch(UrlPointer $url)`. The adapter passes `$url->toString()` to the library.
- `findSummary(UrlPointer $url)` and `find(UrlPointer $url)`. `save(Archive $archive): void` stays. `Archive::$url` and `ArchiveSummary::$url` stay strings. The service copies `toString()` into `Archive`.
- The in-memory shelf stays keyed by a string. The store calls `toString()` at that boundary. SQLite binds `toString()`.
- `CreateArchiveBody::$url` stays `?string`. The controller calls `archive(new UrlPointer($body->url ?? throw new \LogicException('NotNull guarantees url.')))`.
- `#[Exclude]` on `UrlPointer`. Docs and comments in English. PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.

## Review Focus

1. A posted `\0https://example.com/a\0` is stored and answered as `https://example.com/a`. Task 2 `test_leading_and_trailing_nul_in_the_body_are_not_part_of_the_stored_url`.
2. A later `find` of `new UrlPointer("\0https://example.com/a\0")` returns the row saved from `new UrlPointer('https://example.com/a')`. Task 2 `test_a_nul_wrapped_pointer_finds_the_row_saved_from_the_clean_pointer`.
3. `"\0\0"` becomes `''` and the constructor does not throw. Task 1 `test_only_nul_bytes_become_an_empty_string`.
4. Casting the pointer to `string` is an `Error`. Task 1 `test_a_string_cast_is_an_error`.
5. A NUL in the middle stays, so `https://example.com/\0a` is not `https://example.com/a`. Task 1 `test_a_nul_in_the_middle_stays`.

---

### Task 1: UrlPointer

**Files:**
- Create: `packages/web-app/src/Archive/UrlPointer.php`
- Test: `packages/web-app/tests/Archive/UrlPointerTest.php`

**Interfaces:**
- Produces: `final readonly class UrlPointer`. `#[Exclude]`. `__construct(string $url)` stores `trim($url, "\0")` in a private property. `toString(): string` returns it. No `__toString`. No other method. No public property.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_leading_and_trailing_nul_bytes_are_removed(): void
{
    self::assertSame('https://example.com/a', (new UrlPointer("\0https://example.com/a\0"))->toString());
}

public function test_a_nul_in_the_middle_stays(): void
{
    self::assertSame("https://example.com/\0a", (new UrlPointer("https://example.com/\0a"))->toString());
}

public function test_a_surrounding_space_stays(): void
{
    self::assertSame(' https://example.com/a', (new UrlPointer(' https://example.com/a'))->toString());
}

public function test_a_trailing_slash_stays(): void
{
    self::assertNotSame(
        (new UrlPointer('https://example.com/a'))->toString(),
        (new UrlPointer('https://example.com/a/'))->toString(),
    );
}

public function test_only_nul_bytes_become_an_empty_string(): void
{
    self::assertSame('', (new UrlPointer("\0\0"))->toString());
}

public function test_a_string_cast_is_an_error(): void
{
    $this->expectException(\Error::class);

    $unused = (string) new UrlPointer('https://example.com/a');
    self::assertNotSame('', $unused);
}
```

- [ ] **Step 2: Run `./bin/test web-app -- --filter UrlPointerTest`.** Expected: FAIL, class `UrlPointer` not found.

- [ ] **Step 3: Implement** `UrlPointer` as the Interfaces block says. JSDoc on the class and on `toString()`.

- [ ] **Step 4: Run `./bin/test web-app -- --filter UrlPointerTest`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): add the url pointer`.

### Task 2: Ports take the pointer

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveService.php`, `ArchiveStore.php`, `ArchiveLock.php`, `ArticleFetcher.php`, `ArticleReaderAdapter.php`, `Sqlite/SqliteArchiveStore.php`, `Lock/FlockArchiveLock.php`, `Api/Controller/CreateArchiveController.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`, `FakeArchiveLock.php`, `FakeArticleFetcher.php`, `InMemory/InMemoryArchiveStore.php`
- Modify tests: `tests/Archive/ArchiveServiceTest.php`, `ArchiveStoreContractTest.php`, `ArchiveServiceFactoryTest.php`, `InMemory/InMemoryArchiveStoreTest.php`, `InMemory/InMemoryArchiveStoreFactoryTest.php`, `Lock/FlockArchiveLockTest.php`, `Api/Resolver/ArchiveServiceResolverTest.php`, `Integration/CreateArchiveRouteTest.php`
- Modify: `packages/web-app/doc/features/0002-server/feature.md`

**Interfaces:**
- Consumes: `UrlPointer` from Task 1.
- Produces: the signatures in Global Constraints. `FakeArticleFetcher::willReturn`, `willThrow`, and `fetchCount` keep `string $url`. `fetch(UrlPointer $url)` looks up and counts `$url->toString()`. `FakeArchiveLock::$urls` and `FakeArchiveStore` lookup lists stay `list<string>` of `toString()`. The shelf methods keep `string $url`.

- [ ] **Step 1: Write the failing tests** before changing production signatures, so the new cases fail on the current `string` parameters.

`ArchiveStoreContractTest::test_a_nul_wrapped_pointer_finds_the_row_saved_from_the_clean_pointer`: save an archive whose `url` is `https://example.com/a`. `find(new UrlPointer("\0https://example.com/a\0"))` equals that archive. `findSummary` of that same pointer equals the light summary. Run it for both stores.

`CreateArchiveRouteTest::test_leading_and_trailing_nul_in_the_body_are_not_part_of_the_stored_url`: `willReturn('https://example.com/a', PipelineFixtures::success())`, then `postUrl($client, "\0https://example.com/a\0")`. Status 201. Body `{"url":"https://example.com/a","title":"Title"}`.

`ArchiveServiceTest`: replace `archive($string)` with `archive(new UrlPointer($string))`. `find` and `findSummary` in that test take `new UrlPointer(...)`. `test_leading_and_trailing_nul_are_trimmed_before_lock_and_store` calls `archive(new UrlPointer("\0" . self::URL . "\0"))` and still asserts the lock, the summary lookups, the saved URLs, and the fetch count are `self::URL`. `test_a_nul_in_the_middle_stays_part_of_the_url` and `test_surrounding_spaces_stay_part_of_the_url` pass `new UrlPointer($url)` and keep their string assertions. Do not add a trim inside the service.

Every other `archive(`, `exclusive(`, `find(`, `findSummary(`, and `fetch(` that passes a URL string in the files listed above passes `new UrlPointer(...)`. `willReturn` and `willThrow` stay on the clean string. The child in `FlockArchiveLockTest` constructs `new UrlPointer($url)` before `exclusive`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'ArchiveStoreContractTest::test_a_nul_wrapped_pointer_finds_the_row_saved_from_the_clean_pointer|ArchiveServiceTest'`.** Expected: FAIL, `UrlPointer` is not a `string`. The new route case can already pass, because `ArchiveService` still trims. It is the pin that the clean URL survives the move, not the red signal.

- [ ] **Step 3: Implement** the signature change. Delete the `trim` in `ArchiveService::archive`. `fromSuccess` sets `Archive::$url` from `$url->toString()`. `FlockArchiveLock` uses `$url->toString()` in the key. `ArticleReaderAdapter` passes `$url->toString()` to the library. `SqliteArchiveStore` binds `$url->toString()` and keeps `fetchRow(string $sql, string $url)`. `InMemoryArchiveStore` calls `toString()` before the shelf. The controller is the one line in Global Constraints. In `feature.md`, link this plan from the URL pointer note and from the Specs and plans row, and delete the sentence that says the code is not updated yet.

- [ ] **Step 4: Run `./bin/test web-app` and `./bin/quality`.** Expected: both exit 0.

- [ ] **Step 5: Commit** `refactor(web-app): pass a url pointer through the archive ports`.
