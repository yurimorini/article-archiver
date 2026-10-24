# Follow redirect hops

Design for ticket [02 — Follow redirect hops up to the cap](../tickets/02-follow-redirect-hops.md). The contract is [redirect-hops.md](../specs/redirect-hops.md). Ticket 01 placed the cap, the entry URL, and the empty hop history. This ticket fills the loop. It does not move those fields.

## Intent

A saved URL often answers with a redirect. The caller sets a hop cap from 1 to 5 and gets the article from the last 2xx page, plus the entry URL, the final URL, and the hop history. Each next page is resolved, checked, and DNS-pinned before it is fetched. The HTTP client does not follow a redirect by itself. Cap 0 stays today's refusal.

## Goals

- A cap from 1 to 5 follows that many redirect hops and lands on a 2xx.
- The hop history lists the canonical request URLs left behind, in order, and does not include the landing page. The hop count is the length of that list.
- A relative `Location` is resolved against the current hop before the next check. Another host, scheme, or port is fetched when the guard accepts it, with a new DNS pin.
- One request does not follow a redirect. The body of a redirect is not read. Content type and size apply only to the last 2xx.
- A further followable hop after the cap is spent fails with `HttpFetcherError::RedirectLimit`, distinct from `HttpFetcherError::Redirect`.
- A 300, a 304, or a 301, 302, 303, 307, or 308 with no usable `Location` fails with `HttpFetcherError::Redirect`, including when the cap is already spent. Cap 0 fails with that same error.
- A `Location` the guard rejects fails as `OrchestratorError::UrlGuard`, the same rejection as a rejected entry URL.
- A later hop that answers 404 or 500, fails in transport, has a disallowed content type, or exceeds the size limit fails with that existing failure. No earlier page is returned.
- An empty result after one or more hops does not carry the entry URL or the hop history.

## Non-goals

- Meta refresh, script redirects, and canonical links.
- Detecting a redirect loop earlier than the cap.
- Cookies, login detection, and a check that the landed page is the same article.
- A command flag, or a new Eleanor archive failure code. Eleanor keeps cap 0.
- Merging two entry URLs that share a final URL.

## Anti-goals

- Letting the HTTP client follow a redirect.
- Fetching a `Location` that has not been guarded and DNS-pinned.
- Reading or extracting the body of a redirect response.
- Putting the entry URL or the hop history on an empty result or on a thrown failure.
- Treating cap 0 as `RedirectLimit`.

## Constraints

- `HttpFetcher` stays one pinned GET. `allow_redirects` stays false. It does not read `maxRedirectHops`.
- The next request is always a GET, including after 307 and 308.
- No cookie jar and no `Cookie` header are added.
- The entry URL stored on the article is the `fetchArticle` argument, unchanged.
- The final URL is `document.sourceUrl`, the canonical request URL the guard built for the landing hop. There is no second copy.
- The history values are those canonical request URLs, not the raw `Location` text and not the raw entry string.
- No new Composer package. Relative resolution is RFC 3986, implemented in the helper.
- Identifiers, docs, and exception messages are English.

## Approaches

**Catch `HttpFetcherError::Redirect` and read `Location` off the exception.** Rejected. A hop inside the cap is not a failure. The cap lives on the helper, so the fetcher cannot decide whether the 3xx is an error. Every followed hop would be a thrown exception.

**Return the redirect fact.** Chosen. `HttpFetcher::fetch` returns the page, or the status and the first `Location`, and does not throw on a 3xx. `GuardedFetch` holds the cap and decides. The fetcher is the only code that sees the HTTP response, so it calls `getHeader('Location')` and keeps the first value.

**Loop inside `HttpFetcher`.** Rejected. A new hop needs `UrlGuard` and a new DNS pin. The fetcher has neither.

**Loop inside `fetchArticle`.** Rejected. The hop history would be visible only by running encoding, extraction, and purification.

## Modules

`RedirectResponse` is a new readonly value in the `HttpFetcher` namespace. It holds `int $statusCode` and `string $location`. `$location` is the first `Location` field line, or `''` when the header is missing. The fetcher does not trim it and does not look at later field lines.

`HttpFetcher::fetch` returns `FetchedPage|RedirectResponse`. For status 300 through 399 it returns a `RedirectResponse` and does not check content type, does not check size, and does not read the body. A 2xx still passes today's content-type and size checks and returns a `FetchedPage`. Any other status still throws `HttpFetcherError::HttpStatus`. Transport still throws `HttpFetcherError::Transport`. The client options stay `allow_redirects` false and `http_errors` false, with the existing DNS pin.

`HttpFetcherError` gains `RedirectLimit`. Its default message is `HTTP redirect hop cap is exhausted`. The fetcher never throws this case. `GuardedFetch` throws `HttpFetcherException` with that case, message `HTTP redirect hop cap is exhausted (status %d)`, and the response status. `HttpFetcherError::Redirect` stays the message `HTTP redirect (status %d) is not followed`, also thrown by `GuardedFetch`, with the status set.

`GuardedFetch` gains a last constructor parameter `int $maxRedirectHops = 0`. The default keeps a forgotten wiring fail-closed. `OrchestratorFactory` passes `$fetchPolicy->maxRedirectHops` from the same `FetchPolicy` it already gives `HttpFetcher`. The helper stores the integer. It does not read the rest of the policy.

`GuardedFetch::fetch` loops. The entry string is guarded once, unchanged, and logged `URL accepted` with `['url' => $entryUrl]`. Each later hop guards the absolute URL resolved from the previous `Location`, and logs `URL accepted` with that absolute string. `Page fetched` is logged only when the fetcher returns a `FetchedPage`, with `['requestUri' => $page->requestUri]`. A redirect fact is not logged as a page.

A followed hop appends the current hop's canonical request URL to the history, then resolves and guards the next URL. The landing page is not appended. The returned `GuardedPage` keeps the original entry string and that history. `GuardedPage`, `PipelineSuccess`, and `PipelineNoContent` do not change shape.

`Orchestrator::fetchArticle` still calls the helper once and still copies `originalUrl` and `hopHistory` only onto `PipelineSuccess`. Its docblock stops saying that this class does not follow redirects. The class still does not retry a failed download.

## Data flow

```text
entryUrl, cap
  → GuardedFetch::fetch
      history = []
      current = guard(entryUrl)
      loop:
        outcome = HttpFetcher::fetch(current)     one GET, redirects not followed
        page → GuardedPage { page, originalUrl: entryUrl, hopHistory }
        not a followable hop → throw Redirect
        count(history) < cap → append current request URI, resolve Location, guard, pin, repeat
        cap == 0 → throw Redirect
        cap spent → throw RedirectLimit
  → EncodingNormalizer, ArticleExtractor, HtmlSanitizer
  → PipelineSuccess { document, warnings, originalUrl, hopHistory }
     or PipelineNoContent, which ignores originalUrl and hopHistory
```

A followable hop is status 301, 302, 303, 307, or 308 whose first `Location` value is not empty. Empty means the header is missing or `trim` of the first value is `''`.

The base for resolution is the current hop's canonical request URL. The fragment of that URL is not part of the base. The resolved string, including a fragment that the `Location` itself carried, is passed to `UrlGuard::guard`. A `Location` that has no fragment does not inherit the previous hop's fragment. An absolute `Location` stays absolute. A scheme-relative `Location` (`//host/...`) takes the current scheme. A path, query, or relative path is resolved against the current URL. The helper then guards that absolute string. The guard's existing rules apply, including a private address, a disallowed scheme, and credentials.

A redirect loop is followed until the cap stops it. There is no set of visited URLs.

## Errors

`GuardedFetch` throws `HttpFetcherException` with `HttpFetcherError::Redirect` when the response is not a followable hop, and when the cap is 0. That includes 300, 304, a followable status with an empty `Location`, and a followable hop that arrives when the cap is 0. It throws `RedirectLimit` only when the cap is from 1 to 5, the history length already equals that cap, and the response is another followable hop. No further GET is made.

`UrlGuardException` from a later hop propagates unchanged. `Orchestrator` wraps it as `OrchestratorError::UrlGuard`.

`HttpFetcherException` for transport, HTTP status, content type, or size from a later hop propagates unchanged. `Orchestrator` wraps it as `OrchestratorError::Fetch`. The thrown `OrchestratorException` has no `originalUrl` and no `hopHistory`.

`ArchiveFailureMapper` maps `RedirectLimit` to `ArchiveFailure::Unexpected`. No new `ArchiveFailure` value is added. Eleanor's archive API keeps cap 0, so this branch is not produced by a normal archive fetch. The import feature names an archive code when it chooses a cap.

`ArticleFetchCli` keeps exit code 3 for `HttpFetcherError::Redirect` only. `RedirectLimit` stays on the existing path for any other download failure, exit code 4. The command has no cap flag, and its default cap is 0, so it does not produce `RedirectLimit`.

## Logging

Info lines for a chain that lands are one `URL accepted` per guarded URL, in order, then one `Page fetched` for the landing page. A failure after one or more guards has those `URL accepted` lines and no `Page fetched`. The later stage lines stay on `Orchestrator`.

## Tests

The external behavior is tested through one library fetch, with the existing pipeline harness: queued HTTP responses and a stubbed address check. No socket and no DNS. Article results show the entry URL, `document.sourceUrl`, and `hopHistory`. Empty results show neither new field. Failures show the error kind. Tests do not assert which type owns the loop.

`HttpFetcher` tests change where they expect a 3xx to throw. A 3xx returns a `RedirectResponse`. A second queued response is not consumed. A 302 with a non-HTML content type, or a body over the size cap, still returns that redirect fact. Two `Location` lines return the first value only. A missing `Location` returns `''`. One pinned GET still has `allow_redirects` false.

`GuardedFetch` and the pipeline cover cap 0, a cap of 1 and of 5 that land on a 2xx, a chain one hop past the cap, a missing `Location`, a 300, a 304, a guard rejection on a later hop, a relative `Location`, a change of host, an empty result after a hop, and a direct 2xx with an empty history. A 404, a transport failure, a disallowed content type, and an oversized body on a later hop keep today's failures and do not return the earlier page. `test_a_positive_cap_still_does_not_follow` is replaced: a positive cap now follows.

An invalid cap stays a `FetchPolicy` construction failure and performs no request. Those tests already exist.

## Foundation revision

When this design is accepted, the Redirects row in `packages/article-reader/doc/features/0002-foundation/design/Orchestrator.md` becomes: `GuardedFetch` follows redirect hops up to the caller cap; `HttpFetcher` stays one pinned GET and returns a redirect fact instead of following.

## Calls

These are settled here so a builder does not guess them.

- The redirect fact is named `RedirectResponse`.
- The new error is named `HttpFetcherError::RedirectLimit`.
- `maxRedirectHops` is the last `GuardedFetch` constructor parameter and defaults to 0.
- A whitespace-only first `Location` is empty, and fails as `Redirect`, before resolution.
- A `Location` with no fragment does not inherit the previous fragment.
- Eleanor maps `RedirectLimit` to `ArchiveFailure::Unexpected` and gains no new public code.
- The command exit code for `RedirectLimit` is 4.

## Left to the builder

- Whether RFC 3986 resolution is a private method or a small class beside `GuardedFetch`. Callers still see one fetch.
- How `PipelineHarness` copies `maxRedirectHops` from the policy into `GuardedFetch`, as long as a test that sets the cap on the policy follows hops.
- The order of assertions inside an existing test, as long as the behavior above stays pinned.
