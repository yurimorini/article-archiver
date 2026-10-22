# Archive command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development (recommended) or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/archives` archives the URL for the configured owner and answers 201, or 200 for an archive already stored, with `url` and `title`.

**Architecture:** A framework-free `Archive` namespace holds the service, the store contracts, and the `ArticleFetcher` port. A temporary `Archive\InMemory` namespace stands in for the SQLite slice. The HTTP layer gets an `OwnerProvider` and a controller that maps the result to a status and a JSON body. The library `Orchestrator` is reached only through `ArticleReaderAdapter`.

**Tech Stack:** PHP ^8.5, Symfony 8.1 (framework-bundle, serializer, validator), PHPUnit ^11, PHPStan level 8, php-cs-fixer. Library: `Yumo\ArtcleReader` from `packages/article-reader`.

**Spec:** [design/ArchiveCommand.md](../design/ArchiveCommand.md). Contract: [archive-command.md](../specs/archive-command.md).

## Global Constraints

- Namespace `Yumo\Eleanor` on `src/`, `Yumo\Eleanor\Tests` on `tests/`. Files in `tests/Archive/`, `tests/Api/Owner/`, `tests/Support/`, `tests/Integration/`.
- No new Composer package. No change under `packages/article-reader`.
- All classes `final`. Value types are `final readonly`. Every class in `src/Archive/` that the container must not build carries `#[Exclude]` (`Symfony\Component\DependencyInjection\Attribute\Exclude`).
- Public classes, properties, and methods carry English PHPDoc as in `.agents/rules/php-docs.md`. Test methods are `test_snake_case`.
- The in-memory classes say in their docblock that they are a temporary stand-in for the SQLite slice.
- The service never reads the owner parameter. The controller never reads it either.
- Response body keys: `url` first, then `title`. Encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Content type `application/json`. No owner email in the body.
- Run tests with `./bin/test web-app`. A task is done only when its tests pass. The last task also runs `./bin/quality`.
- Each task ends with a commit. Messages follow Conventional Commits.

## Review Focus

- A title that decodes back to itself after a quote or a backslash: Task 5 HTTP test.
- A title or excerpt with markup: the library's `PlainText::raw()` already strips it, and the service stores that raw value. Task 3 test pins `raw()` use.
- A failed fetch followed by a retry of the same URL: Task 3 test, the second call fetches again.
- `https://example.com/a` and `https://example.com/a/` are two archives: Task 3 test.
- A second owner on the same shelf cannot read the first owner's archive: Task 2 test.

---

### Task 1: Value types and `Owner`

**Files:**
- Create: `src/Archive/Owner.php`, `src/Archive/Archive.php`, `src/Archive/ArchiveOutcome.php`, `src/Archive/ArchiveResult.php`, `src/Archive/ArchiveException.php`
- Test: `tests/Archive/OwnerTest.php`

**Interfaces:**
- Produces:
  - `Owner(public string $email)`.
  - `Archive(public string $url, public string $title, public ?string $excerpt, public ?string $siteName, public string $html)`.
  - `enum ArchiveOutcome { case Written; case AlreadyStored; }`.
  - `ArchiveResult(public Archive $archive, public ArchiveOutcome $outcome)`.
  - `ArchiveException extends \RuntimeException`, constructor `(string $message, ?\Throwable $previous = null)`.

- [ ] **Step 1: Write the failing test.** `OwnerTest::test_keeps_the_email`: `(new Owner('ada@example.com'))->email` is `'ada@example.com'`.
- [ ] **Step 2: Run it.** `./bin/test web-app`. Expected: FAIL, class `Owner` not found.
- [ ] **Step 3: Create the five types** with the signatures above. `Owner`, `Archive`, `ArchiveOutcome`, `ArchiveResult`, and `ArchiveException` carry `#[Exclude]`. `ArchiveException` passes `$previous` to the parent and uses code 0.
- [ ] **Step 4: Run tests.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(web-app): add archive value types`.

### Task 2: Store contracts and the in-memory stand-in

**Files:**
- Create: `src/Archive/ArchiveStore.php`, `src/Archive/ArchiveStoreFactory.php`, `src/Archive/InMemory/InMemoryArchiveShelf.php`, `src/Archive/InMemory/InMemoryArchiveStore.php`, `src/Archive/InMemory/InMemoryArchiveStoreFactory.php`
- Test: `tests/Archive/InMemory/InMemoryArchiveStoreTest.php`, `tests/Archive/InMemory/InMemoryArchiveStoreFactoryTest.php`

**Interfaces:**
- Consumes: `Owner`, `Archive` from Task 1.
- Produces:
  - `interface ArchiveStore { public function find(string $url): ?Archive; public function save(Archive $archive): void; }`.
  - `interface ArchiveStoreFactory { public function create(Owner $owner): ArchiveStore; }`.
  - `InMemoryArchiveShelf::find(Owner $owner, string $url): ?Archive` and `::save(Owner $owner, Archive $archive): void`, keyed by owner email then `$archive->url`. No constructor arguments. A container service, shared.
  - `InMemoryArchiveStore(Owner $owner, InMemoryArchiveShelf $shelf) implements ArchiveStore`, `#[Exclude]`.
  - `InMemoryArchiveStoreFactory(InMemoryArchiveShelf $shelf) implements ArchiveStoreFactory`.

- [ ] **Step 1: Write the failing tests.**
  - `InMemoryArchiveStoreTest::test_unknown_url_is_absent`: `find('https://example.com/a')` is null.
  - `::test_save_then_find_returns_the_same_archive`: `assertSame($archive, $store->find($archive->url))`.
  - `::test_two_stores_for_one_owner_share_rows`: a second store with the same `Owner` email and shelf finds what the first saved.
  - `::test_a_store_for_another_owner_does_not_see_the_archive`: another email, same shelf, `find` is null.
  - `InMemoryArchiveStoreFactoryTest::test_stores_made_for_one_owner_share_rows` and `::test_stores_made_for_two_owners_do_not`.
- [ ] **Step 2: Run them.** Expected: FAIL, classes not found.
- [ ] **Step 3: Implement** the two interfaces and the three in-memory classes. The shelf is an array property. The factory has no state besides the shelf.
- [ ] **Step 4: Run tests.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(web-app): add archive store contracts and in-memory stand-in`.

### Task 3: Fetcher port, test support, service, and its factory

**Files:**
- Create: `src/Archive/ArticleFetcher.php`, `src/Archive/ArchiveService.php`, `src/Archive/ArchiveServiceFactory.php`, `tests/Support/FakeArticleFetcher.php`, `tests/Support/PipelineFixtures.php`
- Test: `tests/Archive/ArchiveServiceTest.php`, `tests/Archive/ArchiveServiceFactoryTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 2. Library types `Yumo\ArtcleReader\Orchestrator\{PipelineSuccess, PipelineNoContent, PipelineWarning, PipelineWarningCode, OrchestratorException, OrchestratorError}`, `Yumo\ArtcleReader\HtmlSanitizer\SafeDocument`, `Yumo\ArtcleReader\ArticleExtractor\PlainText`.
- Produces:
  - `interface ArticleFetcher { public function fetch(string $url): PipelineSuccess|PipelineNoContent; }`.
  - `ArchiveService(ArchiveStore $store, ArticleFetcher $fetcher)`, `#[Exclude]`, `archive(string $url): ArchiveResult`.
  - `ArchiveServiceFactory(ArticleFetcher $fetcher, ArchiveStoreFactory $stores)`, `create(Owner $owner): ArchiveService`.
  - `FakeArticleFetcher implements ArticleFetcher`: `willReturn(string $url, PipelineSuccess|PipelineNoContent $outcome): void`, `willThrow(string $url, \Throwable $failure): void`, `fetchCount(?string $url = null): int`. An unregistered URL throws `new OrchestratorException(OrchestratorError::UrlGuard)`. Every `fetch` call counts, including one that throws.
  - `PipelineFixtures::success(string $title = 'Title', ?string $excerpt = null, ?string $siteName = null, string $html = '<p>Body</p>', array $warnings = [], string $sourceUrl = 'https://example.com/a'): PipelineSuccess` and `::noContent(string $sourceUrl = 'https://example.com/a'): PipelineNoContent`, built with `PipelineNoContent::fromSanitizedEmpty()` on an empty `SafeDocument`.

- [ ] **Step 1: Write the support classes** (no test of their own beyond their use here) and the failing tests.
  - `ArchiveServiceTest`:
    - `test_first_archive_fetches_saves_and_is_written`: outcome `Written`, one fetch, `store->find($url)` returns the archive.
    - `test_second_archive_is_already_stored_and_does_not_fetch`: outcome `AlreadyStored`, same `Archive` object, `fetchCount()` is 1.
    - `test_archive_holds_the_five_fields_as_plain_values`: values from `success('Title', 'Excerpt', 'Site', '<p>Body</p>')`.
    - `test_excerpt_and_site_name_stay_null_when_missing`.
    - `test_url_is_the_string_passed_in_not_the_source_url`: fixture `sourceUrl` differs from the argument.
    - `test_warning_is_left_out_of_the_archive`: a fixture with an `EncodingDegraded` warning gives the same `Archive` fields.
    - `test_no_content_becomes_archive_exception_and_stores_nothing`.
    - `test_thrown_failure_becomes_archive_exception_with_that_cause`: `getPrevious()` is the same instance.
    - `test_a_failed_fetch_leaves_the_next_call_free_to_fetch`: two calls, two fetches, both throw.
    - `test_trailing_slash_is_another_archive`.
  - `ArchiveServiceFactoryTest::test_second_service_for_one_owner_sees_the_first_archive`: real `InMemoryArchiveStoreFactory`, the fake, `create($owner)->archive($url)` twice from two `create` calls: `Written` then `AlreadyStored`.
- [ ] **Step 2: Run them.** Expected: FAIL, classes not found.
- [ ] **Step 3: Implement `ArchiveService::archive`** as the five steps in the spec's Module section. Catch `\Throwable` around `fetch` only. Title, excerpt, and site name come from `$success->document` through `PlainText::raw()`.
- [ ] **Step 4: Implement** `ArticleFetcher` and `ArchiveServiceFactory`.
- [ ] **Step 5: Run tests.** Expected: PASS.
- [ ] **Step 6: Commit.** `feat(web-app): add archive service and factory`.

### Task 4: `OwnerProvider`

**Files:**
- Create: `src/Api/Owner/OwnerProvider.php`, `src/Api/Owner/ConfiguredOwnerProvider.php`
- Test: `tests/Api/Owner/ConfiguredOwnerProviderTest.php`

**Interfaces:**
- Consumes: `Owner`.
- Produces: `interface OwnerProvider { public function owner(): Owner; }`. `ConfiguredOwnerProvider(string $email) implements OwnerProvider`, returning `new Owner($email)`.

- [ ] **Step 1: Failing test.** `test_returns_an_owner_with_the_configured_email`.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement** both types.
- [ ] **Step 4: Run tests.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(web-app): add owner provider`.

### Task 5: Adapter, wiring, controller, and HTTP tests

**Files:**
- Create: `src/Archive/ArticleReaderAdapter.php`, `config/services_test.yaml`
- Modify: `config/services.yaml`, `src/Api/Controller/CreateArchiveController.php`, `tests/Integration/CreateArchiveRouteTest.php`, `tests/Integration/LogApiRequestTest.php`

**Interfaces:**
- Consumes: Tasks 1 to 4.
- Produces: `ArticleReaderAdapter(Orchestrator $orchestrator) implements ArticleFetcher`, returning `$orchestrator->fetchArticle($url)`.

- [ ] **Step 1: Write the failing HTTP tests** in `CreateArchiveRouteTest`. Each test gets the fake with `static::getContainer()->get(FakeArticleFetcher::class)` and calls `$client->disableReboot()` before its first request.
  - Replace `test_accepted_post_returns_the_empty_route_stub` with `test_new_url_returns_201_and_the_archive_json`: route `create_archive`, 201, `application/json`, body `{"url":"https://example.com/a","title":"Title"}`, no owner email. Keep the three existing request shapes (query string with charset, `Accept: text/html`, extra property) as a data provider of the same assertions.
  - `test_same_url_again_returns_200_with_the_same_body_and_one_fetch`.
  - `test_a_different_url_returns_201_and_fetches_again`: `fetchCount()` is 2.
  - `test_title_with_quote_and_backslash_decodes_to_the_same_title`.
  - `test_missing_title_is_an_empty_string`: `"title":""`.
  - `test_refused_before_the_archive_is_not_success_json`, data provider over `""`, `" "`, `not-a-url` (unregistered in the fake), a `PipelineFixtures::noContent()` registered URL, and a URL registered with `willThrow(new \RuntimeException())`. Assert the content type is not `application/problem+json` and the body is not the success JSON for that URL.
  - Keep the 400, 415, HEAD, GET, trailing-slash, and unprefixed cases untouched.
- [ ] **Step 2: Update `LogApiRequestTest`.** Its `postArchives` helper registers `PipelineFixtures::success()` for `https://example.test/a` in the fake. `test_post_stores_the_sent_id_agent_and_referer` asserts 201 and `{"url":"https://example.test/a","title":"Title"}`.
- [ ] **Step 3: Run `./bin/test web-app`.** Expected: FAIL.
- [ ] **Step 4: Configure the container.**
  - `config/services.yaml`: parameter `eleanor.owner_email: 'owner@example.com'`. Service `Yumo\ArtcleReader\Orchestrator\Orchestrator` with `factory: ['Yumo\ArtcleReader\Orchestrator\OrchestratorFactory', 'create']`. `Yumo\Eleanor\Api\Owner\ConfiguredOwnerProvider` with `arguments: { $email: '%eleanor.owner_email%' }`. The other aliases are automatic.
  - `config/services_test.yaml`: parameter `eleanor.owner_email: 'owner@example.test'`. `Yumo\Eleanor\Tests\Support\FakeArticleFetcher` as a `public: true` service. Alias `Yumo\Eleanor\Archive\ArticleFetcher` to it. Check that the `Yumo\Eleanor\Tests\` namespace loads in the `test` environment. If the `src/` resource does not reach it, declare the service by class in this file.
- [ ] **Step 5: Implement `ArticleReaderAdapter`** and rewrite `CreateArchiveController`. The constructor takes `ArchiveServiceFactory` and `OwnerProvider`. The action keeps its route attribute and `MapRequestPayload` parameter, and implements the controller bullets of the spec. `$body->url ?? throw new \LogicException('NotNull guarantees url.')`. Status 201 for `Written`, 200 for `AlreadyStored`. Update the controller docblock: it is no longer the empty-route stub.
- [ ] **Step 6: Run `./bin/test web-app`.** Expected: all PASS, including the kernel boot test.
- [ ] **Step 7: Commit.** `feat(web-app): archive the posted URL for the configured owner`.

### Task 6: README, quality gate, and status

**Files:**
- Modify: `README.md`, `doc/features/0002-server/feature.md`

- [ ] **Step 1: README.** Replace "The response body is `{"ok":true}`." with the 201 body `{"url":"https://example.com/a","title":"..."}` and a note that a repeat POST returns 200 with the same body. Add one sentence naming `eleanor.owner_email` in `config/services.yaml` as the place to set the owner email. State that the archive lives in memory until the SQLite slice.
- [ ] **Step 2: Run `./bin/quality`.** Expected: exit 0, both `cs-check` and `phpstan` clean. If `cs-check` fails, run `./bin/cs-fix` then `./bin/quality` again. A PHPStan failure is fixed in the code, not suppressed.
- [ ] **Step 3: Run `./bin/test web-app`.** Expected: all PASS.
- [ ] **Step 4: Update `feature.md`.** Set slice 5 to `Done` with links to the spec and this plan. Update the Current status rows: next slice 6, application code, specs and plans.
- [ ] **Step 5: Manual check.** `./bin/docker-serve web-app`, then the README curl twice. A real URL is needed for the first 201. Report that the check ran or that it did not.
- [ ] **Step 6: Commit.** `docs(web-app): document the archive command and close slice 5`.

---

## Self-review

- **Spec coverage:** Module types, flow steps, owner provider, configuration, error rules, the support fake, every listed unit test, the HTTP cases, the `LogApiRequestTest` change, the README, and `./bin/quality` each map to a task above. The ADR status edits were done while writing the spec.
- **Type consistency:** `ArchiveService(ArchiveStore, ArticleFetcher)` in Tasks 3 and 5. `ArchiveServiceFactory::create(Owner)` takes the owner only to give it to the store factory.
- **Open risk:** whether `Yumo\Eleanor\Tests\Support\FakeArticleFetcher` is reachable as a container service in `test`. Task 5 Step 4 tells the implementer what to do.
