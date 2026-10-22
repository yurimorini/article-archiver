# Fetch Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The fetch seam returns an Article or throws `ArchiveException`, and the archive command no longer names a reader type.

**Architecture:** `Article` is the purified body and has no URL. `ArticleFetcher::fetch` returns that body. `ArticleReaderAdapter` calls the final `Orchestrator` and maps its outcome through `ArchiveFailureMapper`, which only the adapter constructs. `ArchiveService` copies the Article onto the Archive. The HTTP codes stay as they are.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 11, `yumo/article-reader`, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/design/ArchiveCommand.md` and `packages/web-app/doc/features/0002-server/specs/fetch-seam.md`. [adr/0009](../../../adr/0009-fetch-seam-returns-an-article.md) is the decision.

## Global Constraints

- `Article` fields are `string $title`, `?string $excerpt`, `?string $siteName`, `string $html`. No URL. No warnings. `#[Exclude]`.
- `ArticleFetcher::fetch(UrlPointer $url): Article`. A failure throws `ArchiveException`.
- The adapter calls `fetchArticle($url->toString())`. Title, excerpt, and site name come from `PlainText::raw()`. Excerpt and site name stay null when the document has none. HTML is the document HTML. Warnings are not read.
- `PipelineNoContent` and `OrchestratorException` become `ArchiveException` through `ArchiveFailureMapper`. The adapter constructs the mapper. A thrown library failure stays the cause. An empty page has no cause.
- The adapter catches `OrchestratorException` only. It does not catch `\Throwable`.
- `Orchestrator::fetchArticle` wraps every collaborator throwable. Do not add an `Orchestrator` test double. Do not write a test whose only purpose is to force a non-`OrchestratorException` out of `fetchArticle`.
- `ArchiveService` constructor is `ArchiveStore`, `ArticleFetcher`, `ArchiveLock`, `LoggerInterface`. It does not take `ArchiveFailureMapper` and does not import a reader type. `url` on the Archive is `$url->toString()`.
- A save failure stays `archive_not_written`. Domain log lines stay as they are. The library does not change. Wire codes and the fixed messages stay.
- `FakeArticleFetcher::willReturn(string $url, Article $article)`. `willThrow(string $url, ArchiveException $failure)`. An unregistered URL throws `ArchiveException` with `ArchiveFailure::UrlRejected`. `fetchCount` stays.
- Command tests and kernel tests do not import a reader type. Docs and comments in English. PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.
- `feature.md` links this plan from the Fetch seam section. Do not add a second link.

## Review Focus

1. A missing excerpt or site name is stored as null, not `""`. Task 2 `test_excerpt_and_site_name_stay_null_when_missing`.
2. A pipeline warning does not become a field on the Article or the Archive. Task 3 `test_success_drops_warnings`.
3. An empty page has no cause. A library exception that the orchestrator throws keeps that exception as the cause. Task 3 `test_empty_pages_have_no_cause` and `test_a_library_failure_keeps_its_cause`.
4. Upstream status `503` is both the exception's `upstreamStatus` and the HTTP message `The page's site answered with status 503.` Task 3 maps it. Task 2's route provider still asserts the message from an `ArchiveException` built with that status.
5. `fetchArticle` cannot throw a non-`OrchestratorException`. Task 2 deletes the three command tests that threw `\Error` or `\RuntimeException` through the fake, and does not replace them.

---

### Task 1: Article

**Files:**
- Create: `packages/web-app/src/Archive/Article.php`
- Test: `packages/web-app/tests/Archive/ArticleTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `final readonly class Article` with public `string $title`, `?string $excerpt`, `?string $siteName`, `string $html`. `#[Exclude]`.

- [ ] **Step 1: Write the failing test**

`ArticleTest::test_holds_the_purified_body`: `new Article('Title', 'Excerpt', 'Site', '<p>Body</p>')` exposes those four values. `new Article('', null, null, '')` exposes an empty title, null excerpt, null site name, and empty HTML.

- [ ] **Step 2: Run `./bin/test web-app -- --filter ArticleTest`.** Expected: FAIL, class `Article` not found.

- [ ] **Step 3: Add `Article`.** No URL property and no warnings property.

- [ ] **Step 4: Run `./bin/test web-app -- --filter ArticleTest`.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/Article.php packages/web-app/tests/Archive/ArticleTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): add the article body

EOF
)"
```

### Task 2: The command speaks Article

**Files:**
- Modify: `packages/web-app/src/Archive/ArticleFetcher.php`
- Modify: `packages/web-app/src/Archive/ArticleReaderAdapter.php`
- Modify: `packages/web-app/src/Archive/ArchiveService.php`
- Modify: `packages/web-app/tests/Support/FakeArticleFetcher.php`
- Modify: `packages/web-app/tests/Support/PipelineFixtures.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceTest.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceFactoryTest.php`
- Modify: `packages/web-app/tests/Api/Resolver/ArchiveServiceResolverTest.php`
- Modify: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`
- Modify: `packages/web-app/tests/Integration/ErrorResponseRouteTest.php`
- Modify: `packages/web-app/tests/Integration/LogApiRequestTest.php`

**Interfaces:**
- Consumes: `Article` from Task 1. `ArchiveFailureMapper::fromOrchestrator` and `fromEmpty` stay.
- Produces: `ArticleFetcher::fetch(UrlPointer $url): Article`. `FakeArticleFetcher::willReturn(string $url, Article $article): void`. `FakeArticleFetcher::willThrow(string $url, ArchiveException $failure): void`. `PipelineFixtures::article(string $title = 'Title', ?string $excerpt = null, ?string $siteName = null, string $html = '<p>Body</p>'): Article`. `ArchiveService::__construct(ArchiveStore $store, ArticleFetcher $fetcher, ArchiveLock $lock, LoggerInterface $logger)`.

- [ ] **Step 1: Write the failing command tests**

Switch every `PipelineFixtures::success(...)` in the command, factory, resolver, route, and log tests to `PipelineFixtures::article(...)`. Drop `sourceUrl:` and `warnings:` arguments.

`test_url_is_the_string_passed_in_not_the_source_url` registers `PipelineFixtures::article()` and still asserts the stored URL is the pointer passed to `archive`, not another string.

`test_no_content_becomes_archive_exception_and_stores_nothing` calls `willThrow` with `new ArchiveException(ArchiveFailure::ExtractNoContent)`.

`test_thrown_failure_becomes_archive_exception_with_that_cause` calls `willThrow` with `new ArchiveException(ArchiveFailure::Transport, $cause)` where `$cause` is a `\RuntimeException`, and asserts that same cause.

`test_a_mapped_fetch_failure_logs_failed_without_store` and `test_an_empty_page_logs_failed_without_store` throw `new ArchiveException(ArchiveFailure::Transport)` and `new ArchiveException(ArchiveFailure::SanitizedEmpty)`. The log records stay `archive.start`, `archive.fetch`, `archive.failed` with `code` set to that failure's value and without `store`.

Delete `test_warning_is_left_out_of_the_archive`, `test_an_error_from_the_fetcher_passes_through`, `test_a_runtime_exception_from_the_fetcher_passes_through`, `test_a_throwable_that_passes_through_stores_nothing`, and `test_an_error_from_the_fetcher_logs_start_and_fetch`.

Add `test_an_unregistered_url_is_url_rejected`: no `willReturn` and no `willThrow`. `archive` throws `ArchiveException` with `ArchiveFailure::UrlRejected` and stores nothing.

`ErrorResponseRouteTest::archiveFailureProvider` yields `ArchiveException` values, not reader throwables or `PipelineNoContent`. Each row keeps its status, code, and message. The `upstream_status` row is `new ArchiveException(ArchiveFailure::UpstreamStatus, upstreamStatus: 503)`. `willThrow` receives that exception. Remove the reader imports from this test.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'ArchiveServiceTest|CreateArchiveRouteTest|ErrorResponseRouteTest'`.** Expected: FAIL, `article` is unknown or `willReturn` rejects an `Article`.

- [ ] **Step 3: Implement the seam**

`PipelineFixtures::article` returns `new Article(...)`. Leave `success`, `noArticle`, and `noContent` in place until Task 3.

`ArticleFetcher::fetch` returns `Article`. `FakeArticleFetcher` stores `Article` or `ArchiveException`. An unregistered key throws `new ArchiveException(ArchiveFailure::UrlRejected)`.

`ArticleReaderAdapter::fetch` returns the Article from a `PipelineSuccess`, throws `ArchiveFailureMapper::fromEmpty` for `PipelineNoContent`, and throws `fromOrchestrator` for `OrchestratorException`. Any other throwable is not caught.

`ArchiveService` drops the mapper parameter and the reader imports. `fromSuccess` becomes a copy from `Article` onto `Archive`. `fetch` returns `Article` and does not catch.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS. `ArchiveFailureMapperTest` still passes.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Archive/ArticleFetcher.php packages/web-app/src/Archive/ArticleReaderAdapter.php packages/web-app/src/Archive/ArchiveService.php packages/web-app/tests
git commit -m "$(cat <<'EOF'
feat(web-app): return an article from the fetch seam

EOF
)"
```

### Task 3: Adapter test surface

**Files:**
- Create: `packages/web-app/tests/Archive/ArticleReaderAdapterTest.php`
- Delete: `packages/web-app/tests/Archive/ArchiveFailureMapperTest.php`
- Modify: `packages/web-app/tests/Support/PipelineFixtures.php`

**Interfaces:**
- Consumes: `ArticleReaderAdapter`, `ArchiveFailureMapper`, `Orchestrator`, `PipelineFixtures::success`, `noArticle`, and `noContent`.
- Produces: no new production type. After this task only the adapter test imports a reader outcome.

- [ ] **Step 1: Write the failing adapter tests**

Move `orchestratorFailures` and `test_orchestrator_failures_map_by_stage_and_reason` onto `ArticleReaderAdapterTest`. They still call `new ArchiveFailureMapper()->fromOrchestrator`. `Orchestrator::fetchArticle` cannot emit a guard or fetch failure whose previous is missing or is the wrong type, nor an `HttpStatus` whose status is null. Those rows stay on the mapper call.

`test_empty_pages_have_no_cause` calls `fromEmpty` with `PipelineFixtures::noArticle()` and `noContent()`. Failures are `ExtractNoContent` and `SanitizedEmpty`. `getPrevious()` is null.

`test_success_drops_warnings` builds one `Orchestrator` from test collaborators: a `UrlGuard` with a stub validator, an `HttpFetcher` whose Guzzle client uses `MockHandler`, and the real encoding, extract, and purify collaborators. One 200 HTML response is enough. A degraded conversion is the warning. `fetch` returns an `Article`. The title and HTML are the purified body. The Article has no warning field. This test does not assert a private mapper call.

`test_a_library_failure_keeps_its_cause` uses that same orchestrator shape with a mock response the fetcher turns into `HttpFetcherException` for HTTP status 503. `fetch` throws `ArchiveException` with `ArchiveFailure::UpstreamStatus`, `upstreamStatus` 503, and a previous `OrchestratorException`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter ArticleReaderAdapterTest`.** Expected: FAIL, class `ArticleReaderAdapterTest` not found, or the success case fails because the adapter still returns the library document's warnings to a caller. If the class is missing, add the class with the mapper rows first and leave the two orchestrator tests failing on the fixture.

- [ ] **Step 3: Make the orchestrator cases pass**

Wire the two `fetch` tests. Do not change `Orchestrator`. Do not add a production test double of it. Delete `ArchiveFailureMapperTest`. If `PipelineFixtures::success`, `noArticle`, and `noContent` have no remaining caller outside `ArticleReaderAdapterTest`, move those builders into that test and delete them from `PipelineFixtures`. `article()` stays.

- [ ] **Step 4: Run `./bin/test web-app`.** Expected: PASS. `ArchiveFailureMapperTest` is gone.

- [ ] **Step 5: Run `./bin/quality`.** Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add packages/web-app/tests packages/web-app/src/Archive
git commit -m "$(cat <<'EOF'
test(web-app): prove fetch translation on the adapter

EOF
)"
```
