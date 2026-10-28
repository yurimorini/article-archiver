# Redirect hops — ticket 01 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An article result reports the caller's string, the canonical page URL, and an empty hop history, and a hop cap outside 0 through 5 is rejected before any request.

**Architecture:** `FetchPolicy` stores `maxRedirectHops` and does not follow. `GuardedFetch` guards the caller string once and performs one pinned GET. `Orchestrator` calls that helper, then the existing encoding, extract, and sanitize stages. Only `PipelineSuccess` gains `originalUrl` and `hopHistory`. This ticket never follows a redirect, even when the cap is greater than zero.

**Tech Stack:** PHP 8.4, PHPUnit, Guzzle `MockHandler`, the existing `PipelineHarness` and `FakeSsrfUrlValidator`.

**Spec:** `packages/article-reader/doc/features/0003-redirects/design/RedirectHops.md` and `packages/article-reader/doc/features/0003-redirects/specs/redirect-hops.md`

## Global Constraints

- Hop cap integers are 0 through 5 inclusive. The default is 0. Any other integer throws `InvalidArgumentException` with message `maxRedirectHops must be from 0 through 5` before a request.
- This ticket does not follow a redirect. A `3xx` stays `HttpFetcherError::Redirect` wrapped as `OrchestratorError::Fetch`. A second queued response must not be consumed.
- `originalUrl` is the `fetchArticle` argument, unchanged, including surrounding whitespace and letter case.
- The final URL is `PipelineSuccess::$document->sourceUrl`. Do not add a second final-URL field.
- `hopHistory` is `list<string>`. This ticket stores `[]`. The hop count is `count($hopHistory)`. There is no count property.
- `PipelineNoContent` does not gain `originalUrl` or `hopHistory`. It keeps `sourceUrl`.
- `HttpFetcher` stays one pinned GET with `allow_redirects` false. It does not read `maxRedirectHops`.
- `GuardedFetch` does not take the cap. The cap sits on `FetchPolicy` for ticket 02.
- Identifiers, docs, and exception messages are English. No new Composer packages.
- Verify with `./bin/test` and `./bin/quality` from the repo root, inside the project Docker wrappers. Do not use host `php`.

## Review Focus

- A caller string with leading and trailing spaces is stored as `originalUrl` while `sourceUrl` is the trimmed canonical URL. Pinned in Task 3.
- A caller string whose scheme or host differs only by letter case still yields an empty history and a lowercase canonical `sourceUrl`. Pinned in Task 3.
- `maxRedirectHops` 5 with a `302` queued ahead of a `200` throws `HttpFetcherError::Redirect` and does not return the `200`. Pinned in Task 3.
- A blank body stays `PipelineNoContent` with `sourceUrl` and without `originalUrl` or `hopHistory`. Pinned in Task 4.
- `OrchestratorException` for a redirect has neither `originalUrl` nor `hopHistory`. Pinned in Task 4.

---

### Task 1: Hop cap on FetchPolicy

**Files:**
- Modify: `packages/article-reader/src/HttpFetcher/FetchPolicy.php`
- Test: `packages/article-reader/tests/HttpFetcher/FetchPolicyTest.php`

**Interfaces:**
- Consumes: the existing `FetchPolicy` constructor parameters, unchanged and still defaulted.
- Produces: `FetchPolicy::__construct(..., bool $debug = false, int $maxRedirectHops = 0)` with public `int $maxRedirectHops`. Values outside 0 through 5 throw `InvalidArgumentException` (`maxRedirectHops must be from 0 through 5`).

- [ ] **Step 1: Write the failing tests**

Extend `test_defaults_construct_without_error` with `self::assertSame(0, $policy->maxRedirectHops)`.

Add `test_accepts_boundary_hop_caps`: `new FetchPolicy(maxRedirectHops: 0)` and `new FetchPolicy(maxRedirectHops: 5)` both expose that value.

Add provider cases to `invalidConstructorArgs`:

```php
'negative hop cap' => [
    static fn (): FetchPolicy => new FetchPolicy(maxRedirectHops: -1),
    'maxRedirectHops must be from 0 through 5',
],
'hop cap above five' => [
    static fn (): FetchPolicy => new FetchPolicy(maxRedirectHops: 6),
    'maxRedirectHops must be from 0 through 5',
],
```

- [ ] **Step 2: Run the tests**

Run: `./bin/test article-reader -- --filter FetchPolicyTest`

Expected: FAIL because `maxRedirectHops` is an unknown parameter.

- [ ] **Step 3: Add `maxRedirectHops` as the last constructor parameter**

Default `0`. After the existing checks, reject an integer below 0 or above 5 with that exact message. Do not read the field from `HttpFetcher`.

- [ ] **Step 4: Re-run `FetchPolicyTest`**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/HttpFetcher/FetchPolicy.php packages/article-reader/tests/HttpFetcher/FetchPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): reject a hop cap outside 0 through 5

EOF
)"
```

### Task 2: Article result fields

**Files:**
- Modify: `packages/article-reader/src/Orchestrator/PipelineSuccess.php`
- Test: `packages/article-reader/tests/Orchestrator/PipelineSuccessTest.php`

**Interfaces:**
- Consumes: `SafeDocument`.
- Produces: `PipelineSuccess::__construct(SafeDocument $document, string $originalUrl, array $hopHistory, array $warnings = [])`. `originalUrl` and `hopHistory` are required. `@param list<string> $hopHistory` and `@param list<PipelineWarning> $warnings`. Empty HTML still throws `Successful article HTML must not be empty`.

- [ ] **Step 1: Write the failing tests**

Update the three existing constructions to pass `originalUrl: 'https://example.com/article'` and `hopHistory: []`.

Add `test_stores_the_entry_url_and_an_empty_hop_history`: construct with `originalUrl: '  HTTPS://Example.com/a  '` and `hopHistory: []`. Assert those values are stored unchanged, and `isDegraded()` is false.

Add `test_stores_a_hop_history`: construct with `hopHistory: ['https://example.com/old']`. Assert that list. This pins the property type for ticket 02. Do not make `GuardedFetch` produce that list in this task.

- [ ] **Step 2: Run `PipelineSuccessTest`**

Run: `./bin/test article-reader -- --filter PipelineSuccessTest`

Expected: FAIL because the constructor does not take `originalUrl`.

- [ ] **Step 3: Add the two required parameters after `$document` and before `$warnings`**

Keep the empty-HTML check. Do not default `hopHistory`. Update the one `new PipelineSuccess` call in `Orchestrator::fetchArticle` to pass the `$url` argument and `[]`, so this commit still typechecks. Task 4 moves that call onto `GuardedPage`.

- [ ] **Step 4: Re-run `PipelineSuccessTest` and `OrchestratorTest`**

Run: `./bin/test article-reader -- --filter 'PipelineSuccessTest|OrchestratorTest'`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/Orchestrator/PipelineSuccess.php packages/article-reader/src/Orchestrator/Orchestrator.php packages/article-reader/tests/Orchestrator/PipelineSuccessTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): carry the entry URL and hop history on an article

EOF
)"
```

### Task 3: GuardedFetch

**Files:**
- Create: `packages/article-reader/src/Orchestrator/GuardedPage.php`
- Create: `packages/article-reader/src/Orchestrator/GuardedFetch.php`
- Create: `packages/article-reader/tests/Orchestrator/GuardedFetchTest.php`

**Interfaces:**
- Consumes: `UrlGuard::guard(string): GuardResult`, `HttpFetcher::fetch(SafeFetchTarget): FetchedPage`, `FetchPolicy` from Task 1.
- Produces:

```php
final readonly class GuardedPage
{
    /** @param list<string> $hopHistory */
    public function __construct(
        public FetchedPage $page,
        public string $originalUrl,
        public array $hopHistory,
    ) {}
}

final class GuardedFetch
{
    public function __construct(
        private UrlGuard $urlGuard,
        private HttpFetcher $httpFetcher,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @throws UrlGuardException|HttpFetcherException */
    public function fetch(string $entryUrl): GuardedPage
}
```

`fetch` passes `$entryUrl` to `UrlGuard` unchanged. On success it logs info `URL accepted` with context `['url' => $entryUrl]`, then `HttpFetcher::fetch($result->safe)`. On success it logs info `Page fetched` with context `['requestUri' => $page->requestUri]`. It returns `new GuardedPage($page, $entryUrl, [])`. It does not catch. It does not read `maxRedirectHops`.

- [ ] **Step 1: Write the failing tests**

`test_returns_the_caller_string_and_an_empty_history`: one HTML `200` for `https://example.com/article`. Call `fetch('  HTTPS://Example.com/article  ')`. Assert `originalUrl` is that exact string, `hopHistory` is `[]`, and `page->requestUri` is `https://example.com/article`. Assert info messages are `['URL accepted', 'Page fetched']`.

`test_a_positive_cap_still_does_not_follow`: `HttpFetcher` built with `new FetchPolicy(maxRedirectHops: 5)` and a mock queue of a `302` to `https://example.com/next` then a `200` HTML body. `fetch('https://example.com/article')` throws `HttpFetcherException` with `HttpFetcherError::Redirect`. Info messages are `['URL accepted']`.

Use `FakeSsrfUrlValidator`, `RecordingLogger`, and `MockHandler`, the same way `PipelineHarness` builds a client (`allow_redirects` false, `http_errors` false).

- [ ] **Step 2: Run `GuardedFetchTest`**

Run: `./bin/test article-reader -- --filter GuardedFetchTest`

Expected: FAIL because the classes do not exist.

- [ ] **Step 3: Add `GuardedPage` and `GuardedFetch`**

Document the public class and `fetch` in English. `GuardedPage` properties need a one-line docblock each. Do not loop.

- [ ] **Step 4: Re-run `GuardedFetchTest`**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/Orchestrator/GuardedPage.php packages/article-reader/src/Orchestrator/GuardedFetch.php packages/article-reader/tests/Orchestrator/GuardedFetchTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): guard and fetch one page without following hops

EOF
)"
```

### Task 4: Orchestrator uses GuardedFetch

**Files:**
- Modify: `packages/article-reader/src/Orchestrator/Orchestrator.php`
- Modify: `packages/article-reader/src/Orchestrator/OrchestratorFactory.php`
- Modify: `packages/article-reader/tests/Orchestrator/PipelineHarness.php`
- Modify: `packages/article-reader/tests/Orchestrator/OrchestratorTest.php`
- Modify: `packages/article-reader/tests/Orchestrator/OrchestratorFactoryTest.php`

**Interfaces:**
- Consumes: `GuardedFetch::fetch(string): GuardedPage` and `PipelineSuccess::__construct(SafeDocument $document, string $originalUrl, array $hopHistory, array $warnings = [])`.
- Produces: `Orchestrator::__construct(GuardedFetch $guardedFetch, EncodingNormalizer $encodingNormalizer, ArticleExtractor $articleExtractor, HtmlSanitizer $htmlSanitizer, LoggerInterface $logger = new NullLogger())`. `fetchArticle` still returns `PipelineSuccess|PipelineNoContent`.

- [ ] **Step 1: Extend the failing pipeline assertions**

In `test_happy_path_returns_non_empty_article_html`, assert `$result->originalUrl` is `https://example.com/article`, `$result->hopHistory` is `[]`, and `$result->document->sourceUrl` is that same URL.

In `test_degraded_encoding_keeps_the_article_and_adds_a_warning`, assert the same `originalUrl` and `[]` history.

In `test_blank_body_is_extract_no_content`, assert the result is `PipelineNoContent`, `sourceUrl` is `https://example.com/article`, and `property_exists($result, 'originalUrl')` and `property_exists($result, 'hopHistory')` are false.

In `test_fetch_failure_is_wrapped_and_redirect_is_not_followed`, queue a second `200` HTML response after the `302`. Keep the existing `OrchestratorError::Fetch` and `HttpFetcherError::Redirect` assertions. Assert info messages are `['URL accepted']`. Assert the exception has no `originalUrl` or `hopHistory` properties.

`PipelineHarness::make` builds `new GuardedFetch($urlGuard, $httpFetcher, $logger)` and passes that as the orchestrator's first argument. `OrchestratorFactory::create` does the same with the `FetchPolicy` it already builds for `HttpFetcher`. Update `OrchestratorFactoryTest` to read `guardedFetch` on the orchestrator, then `httpFetcher` and `urlGuard` on that helper. The fetcher still shares the orchestrator logger and the `FetchPolicy`. The helper's logger is that same logger. Keep the existing extractor and sanitizer assertions.

- [ ] **Step 2: Run `OrchestratorTest` and `OrchestratorFactoryTest`**

Run: `./bin/test article-reader -- --filter 'OrchestratorTest|OrchestratorFactoryTest'`

Expected: FAIL because `Orchestrator` still requires `UrlGuard` and `HttpFetcher`.

- [ ] **Step 3: Replace the guard and fetch collaborators**

Delete `guardUrl` and `fetchPage`. `fetchArticle` calls `$this->guardedFetch->fetch($url)`, keeps `originalUrl` and `hopHistory`, and passes `$page->page` into `normalizeEncoding`. Pass those two values into `new PipelineSuccess`. Do not pass them into `PipelineNoContent`. Update the class docblock: this ticket does not follow redirects, and a failed download is not retried. `GuardedFetch` already logs `URL accepted` and `Page fetched`. Do not log them again.

- [ ] **Step 4: Re-run the orchestrator tests and the package quality gate**

Run: `./bin/test article-reader -- --filter 'OrchestratorTest|OrchestratorFactoryTest|GuardedFetchTest|PipelineSuccessTest|FetchPolicyTest'`

Expected: PASS.

Run: `./bin/quality article-reader`

Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/Orchestrator/Orchestrator.php packages/article-reader/src/Orchestrator/OrchestratorFactory.php packages/article-reader/tests/Orchestrator/PipelineHarness.php packages/article-reader/tests/Orchestrator/OrchestratorTest.php packages/article-reader/tests/Orchestrator/OrchestratorFactoryTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): report the entry URL on an article result

EOF
)"
```
