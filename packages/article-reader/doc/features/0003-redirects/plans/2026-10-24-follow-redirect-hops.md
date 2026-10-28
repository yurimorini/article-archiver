# Follow redirect hops — ticket 02 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fetch follows redirect hops up to the caller cap, re-checks and re-pins each hop, and an article result reports the entry URL, the final URL, and the hop history.

**Architecture:** `HttpFetcher::fetch` stays one pinned GET and returns a `RedirectResponse` for every 3xx instead of throwing. `GuardedFetch` holds `maxRedirectHops` and decides whether to resolve, guard, and fetch the next URL, or to throw `HttpFetcherError::Redirect` or `HttpFetcherError::RedirectLimit`. `Orchestrator` still calls the helper once. Article fields do not move.

**Tech Stack:** PHP 8.4, PHPUnit, Guzzle `MockHandler`, the existing `PipelineHarness` and `FakeSsrfUrlValidator`.

**Spec:** `packages/article-reader/doc/features/0003-redirects/design/FollowRedirectHops.md` and `packages/article-reader/doc/features/0003-redirects/specs/redirect-hops.md`

## Global Constraints

- Hop cap integers are 0 through 5. The default is 0. Any other integer already throws `InvalidArgumentException` (`maxRedirectHops must be from 0 through 5`) from `FetchPolicy` before a request. Do not retest that rejection except where an existing test still passes.
- A followable hop is status 301, 302, 303, 307, or 308 whose first `Location` value is non-empty after `trim`. Any other 3xx, including 300 and 304, is not a hop.
- Cap 0 and a followable hop throws `HttpFetcherError::Redirect` with message `HTTP redirect (status %d) is not followed`. It is not `RedirectLimit`.
- `RedirectLimit` is thrown only when the cap is 1 through 5, `count($hopHistory)` already equals that cap, and the response is another followable hop. Message: `HTTP redirect hop cap is exhausted (status %d)`. No further GET is made.
- A 300, a 304, or a followable status with an empty `Location` throws `Redirect` even when the cap is already spent.
- The first `Location` field line is the only one used. `getHeader('Location')`, index 0. The fetcher does not trim and does not read later lines.
- A relative `Location` is resolved with RFC 3986 against the current hop's canonical request URL, with that URL's fragment removed from the base. A `Location` with no fragment does not inherit the previous fragment. No new Composer package.
- The next request is always a GET. `allow_redirects` stays false. No cookie jar and no `Cookie` header.
- `HttpFetcher` does not read `maxRedirectHops`. Content type and size apply only to a 2xx. A 3xx body is not read.
- `originalUrl` is the `fetchArticle` argument, unchanged. The final URL is `document.sourceUrl`. `hopHistory` is the list of canonical request URLs left behind, excluding the landing page. `PipelineNoContent` does not gain those fields. A thrown failure carries none of them.
- Eleanor maps `RedirectLimit` to `ArchiveFailure::Unexpected`. No new `ArchiveFailure` value. The command exit code for `RedirectLimit` is 4. Exit code 3 stays `HttpFetcherError::Redirect` only.
- Identifiers, docs, and exception messages are English. No new Composer packages.
- Verify with `./bin/run test` and `./bin/run quality` from the package directory, inside Docker. Do not use host `php`.

## Review Focus

- A first `Location` of only spaces is `Redirect`, and the next queued response is not fetched. Pinned in Task 3.
- A 304 that carries a `Location` is `Redirect` both when the cap still has room and when the cap is already spent. It is not a hop and not `RedirectLimit`. Pinned in Task 3.
- The history stores the canonical URL of the page left behind. A `Location` of `/b` from `https://example.com/a#frag` lands on `https://example.com/b` with no fragment. Pinned in Task 3.
- Cap 0 and a followable hop is `Redirect`, not `RedirectLimit`. Pinned in Task 3.
- Two `Location` lines follow the first only. When that first value is rejected by the guard, the second line is not fetched. Pinned in Task 3.

## File structure

- `HttpFetcher/RedirectResponse.php` — status and the first `Location` string. Created in Task 1.
- `HttpFetcher/HttpFetcher.php` — one GET; 3xx returns `RedirectResponse`. Task 1.
- `HttpFetcher/HttpFetcherError.php` — `RedirectLimit`. Task 2.
- `Orchestrator/GuardedFetch.php` — the hop loop and the cap. Task 1 makes the union compile by throwing `Redirect`. Task 3 replaces that throw with the loop.
- `Orchestrator/OrchestratorFactory.php` and `tests/Orchestrator/PipelineHarness.php` — pass the policy cap into the helper. Task 4.
- `web-app` `ArchiveFailureMapper.php` — exhaustive match gains `RedirectLimit`. Task 2.
- `GuardedPage`, `PipelineSuccess`, and `PipelineNoContent` stay as they are.

---

### Task 1: Return a redirect fact

**Files:**
- Create: `packages/article-reader/src/HttpFetcher/RedirectResponse.php`
- Modify: `packages/article-reader/src/HttpFetcher/HttpFetcher.php`
- Modify: `packages/article-reader/src/Orchestrator/GuardedFetch.php`
- Test: `packages/article-reader/tests/HttpFetcher/HttpFetcherTest.php`

**Interfaces:**
- Consumes: `SafeFetchTarget`, the PSR-7 response from the existing client call.
- Produces:

```php
final readonly class RedirectResponse
{
    public function __construct(
        public int $statusCode,
        public string $location,
    ) {}
}
```

`HttpFetcher::fetch(SafeFetchTarget $target): FetchedPage|RedirectResponse`. For status 300 through 399, return `new RedirectResponse($statusCode, $location)` where `$location` is `getHeader('Location')[0] ?? ''`. Do not trim. Do not check content type, size, or read the body. A 2xx still returns `FetchedPage`. Any other status still throws `HttpFetcherError::HttpStatus`. Transport still throws `HttpFetcherError::Transport`.

Until Task 3, `GuardedFetch::fetch` throws today's redirect when the outcome is a `RedirectResponse`:

```php
throw new HttpFetcherException(
    HttpFetcherError::Redirect,
    sprintf('HTTP redirect (status %d) is not followed', $outcome->statusCode),
    statusCode: $outcome->statusCode,
);
```

Do not follow. Do not log `Page fetched` for that outcome.

- [ ] **Step 1: Write the failing tests**

Replace `test_redirect_status_fails_closed`. One fetch of a 3xx returns a `RedirectResponse` with that status and `location` `https://example.com/other`. It does not throw. Extend `redirectStatuses` with 300, 303, 304, and 308 (301, 302, and 307 stay).

Add `test_redirect_leaves_the_next_response_queued`: queue a 302 to `https://example.com/next`, then a 200 HTML page. The first `fetch` returns the redirect fact. The second `fetch` returns a `FetchedPage` for the 200.

Add `test_redirect_skips_content_type_and_size`: `FetchPolicy` with `maxBytes: 10`, response 302, `Content-Type: text/plain`, body `0123456789abc`, `Location: https://example.com/next`. The result is a `RedirectResponse`. No `ContentType` or `BodyTooLarge` exception.

Add `test_redirect_returns_the_first_location_line`: header `['Location' => ['https://example.com/first', 'https://example.com/second']]`. `location` is `https://example.com/first`.

Add `test_redirect_keeps_a_missing_or_raw_location`: a 302 with no `Location` header has `location` `''`. A 302 with `Location: '   '` has `location` `'   '`.

- [ ] **Step 2: Run the tests**

From `packages/article-reader`:

```bash
./bin/run test -- --filter HttpFetcherTest
```

Expected: FAIL because a 3xx still throws `HttpFetcherException`.

- [ ] **Step 3: Add `RedirectResponse` and return it for every 3xx**

Document the class and both properties in English. Update the `HttpFetcher` class doc: a 3xx is returned, not thrown, and the body is not read. Update `GuardedFetch::fetch` with the throw above so the union typechecks and existing redirect tests still pass. Update its doc to say a redirect fact is refused until the hop loop lands in Task 3. That sentence is replaced in Task 3.

- [ ] **Step 4: Re-run `HttpFetcherTest` and `GuardedFetchTest`**

```bash
./bin/run test -- --filter 'HttpFetcherTest|GuardedFetchTest'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/HttpFetcher/RedirectResponse.php packages/article-reader/src/HttpFetcher/HttpFetcher.php packages/article-reader/src/Orchestrator/GuardedFetch.php packages/article-reader/tests/HttpFetcher/HttpFetcherTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): return a redirect instead of throwing on 3xx

EOF
)"
```

### Task 2: Name the hop-cap failure

**Files:**
- Modify: `packages/article-reader/src/HttpFetcher/HttpFetcherError.php`
- Modify: `packages/article-reader/tests/HttpFetcher/HttpFetcherExceptionTest.php`
- Modify: `packages/web-app/src/Archive/ArchiveFailureMapper.php`
- Modify: `packages/web-app/tests/Archive/ArticleReaderAdapterTest.php`
- Test: `packages/article-reader/tests/Orchestrator/ArticleFetchCliTest.php`

**Interfaces:**
- Consumes: `HttpFetcherException`.
- Produces: `HttpFetcherError::RedirectLimit` with `defaultMessage()` `HTTP redirect hop cap is exhausted`. The fetcher does not throw it. `ArchiveFailureMapper` maps it to `ArchiveFailure::Unexpected`. `ArticleFetchCli::exitCode` returns 4 for it and still returns 3 for `HttpFetcherError::Redirect`.

- [ ] **Step 1: Write the failing tests**

Add the provider row `'redirect limit' => [HttpFetcherError::RedirectLimit, 'HTTP redirect hop cap is exhausted']` in `HttpFetcherExceptionTest`.

Add this yield in `orchestratorFailures`:

```php
yield 'fetch redirect limit' => [
    self::fetchFailure(new HttpFetcherException(
        HttpFetcherError::RedirectLimit,
        'HTTP redirect hop cap is exhausted (status 302)',
        statusCode: 302,
    )),
    ArchiveFailure::Unexpected,
    null,
];
```

In `test_redirect_exits_three_and_other_fetch_failures_exit_four`, add:

```php
$limit = new OrchestratorException(
    OrchestratorError::Fetch,
    '',
    new HttpFetcherException(
        HttpFetcherError::RedirectLimit,
        'HTTP redirect hop cap is exhausted (status 302)',
        statusCode: 302,
    ),
);
self::assertSame(4, ArticleFetchCli::exitCode($limit));
```

The existing live 302 run still asserts exit code 3. The harness cap stays 0.

- [ ] **Step 2: Run the tests**

From `packages/article-reader`:

```bash
./bin/run test -- --filter 'HttpFetcherExceptionTest|ArticleFetchCliTest'
```

From `packages/web-app`:

```bash
./bin/run test -- --filter ArticleReaderAdapterTest
```

Expected: FAIL because `RedirectLimit` is not a case.

- [ ] **Step 3: Add the enum case and the two mappings**

Docblock: the cap was spent on a followable hop. `defaultMessage()` returns that exact string. The mapper arm is `HttpFetcherError::RedirectLimit => ArchiveFailure::Unexpected`. Do not add an `ArchiveFailure` case. Do not change the CLI `if` that returns 3: `RedirectLimit` already falls through to 4.

- [ ] **Step 4: Re-run those tests and `./bin/run quality` in `packages/web-app`**

Expected: PASS, quality exit 0.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/HttpFetcher/HttpFetcherError.php packages/article-reader/tests/HttpFetcher/HttpFetcherExceptionTest.php packages/article-reader/tests/Orchestrator/ArticleFetchCliTest.php packages/web-app/src/Archive/ArchiveFailureMapper.php packages/web-app/tests/Archive/ArticleReaderAdapterTest.php
git commit -m "$(cat <<'EOF'
feat: name a redirect past the hop cap

EOF
)"
```

### Task 3: Follow hops in GuardedFetch

**Files:**
- Modify: `packages/article-reader/src/Orchestrator/GuardedFetch.php`
- Test: `packages/article-reader/tests/Orchestrator/GuardedFetchTest.php`

**Interfaces:**
- Consumes: `HttpFetcher::fetch(): FetchedPage|RedirectResponse`, `UrlGuard::guard(string): GuardResult`, `HttpFetcherError::Redirect` and `RedirectLimit`.
- Produces: `GuardedFetch::__construct(UrlGuard $urlGuard, HttpFetcher $httpFetcher, LoggerInterface $logger = new NullLogger(), int $maxRedirectHops = 0)`. `fetch(string $entryUrl): GuardedPage` still returns the entry string and the hop history. `@throws UrlGuardException|HttpFetcherException`.

`guardedFetch()` passes `$policy->maxRedirectHops` as the last constructor argument. Keep the `FakeSsrfUrlValidator` and the `MockHandler` reachable from a test.

Resolution is a private method or a small class beside `GuardedFetch`. Signature to rely on: given the current canonical request URL and the raw first `Location` string, return the absolute URL passed to the next `guard`. Remove the base fragment before resolving. No package.

Decision, in order, after each GET:

1. `FetchedPage`: log `Page fetched` with `['requestUri' => $page->requestUri]` and return `GuardedPage` with the history so far.
2. Not followable: throw `Redirect` with the status. Followable means status is one of 301, 302, 303, 307, 308 and `trim($location) !== ''`.
3. `count($history) < $maxRedirectHops`: append `$current->requestUri` to `$history`, resolve `$location` against that URI, `guard` the result, log `URL accepted` with `['url' => $resolved]`, and repeat. The entry log stays `['url' => $entryUrl]` and uses the raw argument.
4. `$maxRedirectHops === 0`: throw `Redirect`.
5. Otherwise throw `RedirectLimit` with the status.

A later `UrlGuardException` or `HttpFetcherException` propagates. No visited-URL set. Every request stays a GET through the existing fetcher.

- [ ] **Step 1: Write the failing tests**

Replace `test_a_positive_cap_still_does_not_follow` with `test_a_cap_of_one_follows_one_hop`. Cap 5 is not this test. Policy `maxRedirectHops: 1`, entry `  HTTPS://Example.com/article  `, 302 `Location: /next`, then 200 HTML. Assert `originalUrl` is that exact string, `hopHistory` is `['https://example.com/article']`, `requestUri` is `https://example.com/next`, info messages are `['URL accepted', 'URL accepted', 'Page fetched']`.

Add `test_cap_zero_refuses_a_followable_hop`: cap 0, 302 to `https://example.com/next`, then a 200. Throws `HttpFetcherError::Redirect`, status 302, message `HTTP redirect (status 302) is not followed`. The `MockHandler` still has 1 response. Info messages are `['URL accepted']`. The error is not `RedirectLimit`.

Add `test_a_cap_of_five_lands_on_the_last_hop`: entry `https://example.com/start`, then five 302 responses whose `Location` values are `/h1`, `/h2`, `/h3`, `/h4`, and `/h5`, then a 200. `hopHistory` is `['https://example.com/start', 'https://example.com/h1', 'https://example.com/h2', 'https://example.com/h3', 'https://example.com/h4']`. `requestUri` is `https://example.com/h5`.

Add `test_a_hop_past_the_cap_is_redirect_limit`: cap 1, 302 to `https://example.com/b`, then 302 to `https://example.com/c`, then a 200. Throws `RedirectLimit`, status 302, message `HTTP redirect hop cap is exhausted (status 302)`. The mock still has 1 response. Info messages are `['URL accepted', 'URL accepted']`.

Add `test_a_non_hop_stays_redirect` as a provider. Each case queues the 3xx, then a 200. The fetch throws `Redirect` with that status, not `RedirectLimit`, and the mock still has 1 response.

Cap 5, first response, cap still open:

- 304 with `Location: https://example.com/next`
- 300 with `Location: https://example.com/next`
- 302 with no `Location`
- 302 with `Location: '   '`

Cap 1, second response, cap already spent. First response is 302 to `https://example.com/b`. Second response is one of:

- 304 with `Location: https://example.com/c`
- 300 with `Location: https://example.com/c`
- 301 with no `Location`
- 302 with `Location: '   '`

Add `test_a_relative_location_is_resolved_before_the_guard` with provider rows:

| Base entry | Location | Landed request URI |
| --- | --- | --- |
| `https://example.com/article` | `/next` | `https://example.com/next` |
| `https://example.com/dir/article` | `next` | `https://example.com/dir/next` |
| `https://example.com/a` | `?x=1` | `https://example.com/a?x=1` |
| `https://example.com/a` | `//other.example/x` | `https://other.example/x` |
| `https://example.com/a` | `http://example.com/next` | `http://example.com/next` |
| `https://example.com/a` | `https://example.com:8443/next` | `https://example.com:8443/next` |

Cap 1. One 302 then a 200. `hopHistory` is the canonical entry URL. `requestUri` is the landed URL.

Add `test_a_location_does_not_inherit_the_previous_fragment`: entry `https://example.com/a#frag`, `Location: /b`, cap 1. `hopHistory` is `['https://example.com/a#frag']`. Landed `requestUri` is `https://example.com/b`.

Add `test_two_location_lines_use_the_first`: cap 1, 302 with `Location` lines `http://user:pass@example.com/no` and `https://example.com/yes`, then a 200. Throws `UrlGuardException`. The mock still has 1 response.

Add `test_a_later_hop_failure_does_not_return_the_first_page` with provider rows: a 404 HTML body (`HttpStatus`), `new ConnectException('Connection timed out', new Request('GET', 'https://example.com/next'))` (`Transport`), a 200 `text/plain` (`ContentType`), and a 200 HTML body longer than `maxBytes: 10` (`BodyTooLarge`). Cap 1. First response is 302 to `https://example.com/next`. Each throws that `HttpFetcherError`. The result is not a `GuardedPage`.

Add `test_a_redirect_loop_stops_at_the_cap`: cap 2, entry `https://example.com/a`, then `Location` values `https://example.com/b`, `https://example.com/a`, `https://example.com/b`, then a 200. Throws `RedirectLimit`. History is `['https://example.com/a', 'https://example.com/b']`. The mock still has 1 response.

For the host change, `test_a_relative_location_is_resolved_before_the_guard` already lands on `other.example`. In that row, assert `FakeSsrfUrlValidator::$callCount` is 2. That is the new guard, and the fetcher pins whatever target that guard returns. Do not add a second pin path.

- [ ] **Step 2: Run `GuardedFetchTest`**

```bash
./bin/run test -- --filter GuardedFetchTest
```

Expected: FAIL because a positive cap still throws `Redirect` on the first hop.

- [ ] **Step 3: Replace the Task 1 throw with the loop**

Document the class and `fetch` in English: the helper follows at most `$maxRedirectHops` redirect hops, and each next URL is guarded before the GET. Delete the sentence that says a redirect fact is refused. `Page fetched` only for a `FetchedPage`.

- [ ] **Step 4: Re-run `GuardedFetchTest`**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/src/Orchestrator/GuardedFetch.php packages/article-reader/tests/Orchestrator/GuardedFetchTest.php
git commit -m "$(cat <<'EOF'
feat(article-reader): follow redirect hops up to the cap

EOF
)"
```

### Task 4: Article result through the pipeline

**Files:**
- Modify: `packages/article-reader/tests/Orchestrator/PipelineHarness.php`
- Modify: `packages/article-reader/src/Orchestrator/OrchestratorFactory.php`
- Modify: `packages/article-reader/src/Orchestrator/Orchestrator.php`
- Modify: `packages/article-reader/tests/Orchestrator/OrchestratorTest.php`
- Modify: `packages/article-reader/tests/Orchestrator/OrchestratorFactoryTest.php`
- Modify: `packages/article-reader/doc/features/0002-foundation/design/Orchestrator.md`

**Interfaces:**
- Consumes: `GuardedFetch::__construct(..., int $maxRedirectHops = 0)` and `FetchPolicy::$maxRedirectHops`.
- Produces: `PipelineHarness::make` gains `?FetchPolicy $fetchPolicy = null` after the existing parameters. The harness builds `HttpFetcher` with that policy and passes `$fetchPolicy->maxRedirectHops` into `GuardedFetch`. The default policy cap is 0, so `test_fetch_failure_is_wrapped_and_redirect_is_not_followed` stays a cap-0 `Redirect` and still does not consume the queued 200. `OrchestratorFactory::create` passes the same integer from the `FetchPolicy` it already builds. `fetchArticle` is unchanged except the class docblock: hops are followed by `GuardedFetch` up to the cap, and a failed download is not retried.

- [ ] **Step 1: Write the failing tests**

In `OrchestratorFactoryTest::test_create_without_options_uses_null_logger_and_default_cache_path`, assert `maxRedirectHops` on `guardedFetch` is 0.

In `test_create_shares_one_logger_and_passes_it_to_the_extractor_only_when_debug_is_on`, the `FetchPolicy` is built with `timeoutSeconds: 12` only, so the cap is 0. Change that policy to `new FetchPolicy(timeoutSeconds: 12, maxRedirectHops: 3)` and assert `guardedFetch`'s `maxRedirectHops` is 3. The fetcher still holds that same policy object.

Add `test_a_followed_hop_returns_the_landed_article`: `fetchPolicy: new FetchPolicy(maxRedirectHops: 1)`, 302 `Location: /landed`, then `articleHtml()` at 200. Entry `https://example.com/article`. Assert `PipelineSuccess`, `originalUrl` is that URL, `hopHistory` is `['https://example.com/article']`, `document.sourceUrl` is `https://example.com/landed`, and the HTML is the landed article, not an empty string.

Add `test_an_empty_result_after_a_hop_has_no_entry_url`: same cap and redirect, second body `" \n\t"`. Assert `PipelineNoContent`, `sourceUrl` is `https://example.com/landed`, and `originalUrl` and `hopHistory` are absent.

Add `test_a_later_404_does_not_return_the_earlier_page`: cap 1, 302 to `/next`, then 404 HTML. Throws `OrchestratorError::Fetch` whose previous error is `HttpStatus`. The exception has no `originalUrl` or `hopHistory`.

Add `test_a_lossy_landing_page_keeps_the_warning`: cap 1, 302 to `/landed`, then `articleHtmlWithInvalidUtf8()`. Assert `PipelineSuccess`, `isDegraded()` is true, `originalUrl` and `hopHistory` are set as in the landed-article test, and `sourceUrl` is `https://example.com/landed`.

- [ ] **Step 2: Run the orchestrator tests**

```bash
./bin/run test -- --filter 'OrchestratorTest|OrchestratorFactoryTest'
```

Expected: FAIL because `PipelineHarness::make` has no `fetchPolicy` parameter, and a cap of 1 is not wired.

- [ ] **Step 3: Pass the cap through and correct the orchestrator doc**

Do not change `fetchArticle`'s control flow. Update the Redirects row in `packages/article-reader/doc/features/0002-foundation/design/Orchestrator.md` to: `GuardedFetch` follows redirect hops up to the caller cap; `HttpFetcher` stays one pinned GET and returns a redirect fact instead of following.

- [ ] **Step 4: Re-run the package tests and quality**

From `packages/article-reader`:

```bash
./bin/run test
./bin/run quality
```

Expected: PASS, quality exit 0.

From `packages/web-app`:

```bash
./bin/run test -- --filter ArticleReaderAdapterTest
./bin/run quality
```

Expected: PASS, quality exit 0.

- [ ] **Step 5: Commit**

```bash
git add packages/article-reader/tests/Orchestrator/PipelineHarness.php packages/article-reader/src/Orchestrator/OrchestratorFactory.php packages/article-reader/src/Orchestrator/Orchestrator.php packages/article-reader/tests/Orchestrator/OrchestratorTest.php packages/article-reader/tests/Orchestrator/OrchestratorFactoryTest.php packages/article-reader/doc/features/0002-foundation/design/Orchestrator.md
git commit -m "$(cat <<'EOF'
feat(article-reader): report the landed page after a redirect hop

EOF
)"
```
