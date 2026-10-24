# Redirect hops

Design for ticket [01 — Report the entry URL, the final URL, and the hop count](../tickets/01-report-entry-and-final-url.md). The contract is [redirect-hops.md](../specs/redirect-hops.md). Ticket 02 fills the loop this design leaves empty. It must not move the facts this ticket places.

## Intent

A caller who gets an article needs the string they passed and the canonical URL of the page that was extracted, plus the list of URLs left behind on the way. Today those are one string. The caller string dies inside `UrlGuard`, and only the canonical URL survives as `sourceUrl`.

This ticket does not follow a redirect. It puts the two URLs and an empty hop history on the article result, and it rejects a hop cap outside 0 through 5. The helper that will follow hops exists here so ticket 02 changes the helper and not the result.

## Goals

- An article result carries the entry URL, the final URL, and the hop history.
- The hop count is the length of that history. This ticket's history is empty.
- The hop cap lives on the fetch configuration. Legal values are 0 through 5. The default is 0. Any other integer is rejected before a request.
- A redirect still fails with today's redirect failure. No second request is made.
- An empty result and a thrown failure do not gain the entry URL or the hop history.

## Non-goals

- Following a redirect hop. That is ticket 02.
- Putting the entry URL or the hop history on `FetchedPage`, `Utf8Html`, `ReadableDocument`, `SafeDocument`, or `PipelineNoContent`.
- Changing `SafeFetchTarget`.
- A command flag, or Eleanor storing the final URL.

## Anti-goals

- Letting the HTTP client follow a redirect.
- Fetching a `Location` that has not been guarded and DNS-pinned.
- Treating an empty result as an article result by copying the new facts onto it.

## Constraints

- One pinned GET stays one guarded request. `HttpFetcher` does not learn about hops.
- The body source URL stays the final URL, because relative links resolve against it.
- The entry URL is the string passed to `fetchArticle`, unchanged, including surrounding whitespace.
- The foundation orchestrator document forbids following redirects inside `Orchestrator`. This design revises that, because `Orchestrator` calls the helper and ticket 02's loop lives in the helper.

## Approaches

**Loop inside `HttpFetcher`.** Rejected. A new hop needs a new `UrlGuard` and a new pin. The fetcher does not own the guard, and pulling the guard in would make one GET know about the chain.

**Loop written inline in `fetchArticle`.** Rejected. The hop state would be testable only by running encoding, extraction, and purification.

**Helper the composition root calls.** Chosen. The helper holds the hop state and is testable with a fake guard and a queued HTTP client. `Orchestrator` calls it once, then runs the rest of the pipeline on the returned page. Ticket 01's helper guards and fetches once and returns an empty history. Ticket 02 adds the loop in that helper.

## Modules

`FetchPolicy` gains `maxRedirectHops`. The constructor rejects an integer below 0 or above 5. The default is 0. The fetcher does not read this field in this ticket.

`GuardedFetch` is a new class beside `Orchestrator`. It is not a new pipeline stage. Its constructor takes the `UrlGuard`, the `HttpFetcher`, and the logger the orchestrator already uses. `fetch(string $entryUrl)` guards that string, fetches the target, and returns a `GuardedPage`.

`GuardedPage` holds the `FetchedPage`, the entry URL, and the hop history (`list<string>`). This ticket always returns an empty history. The names `GuardedFetch` and `GuardedPage` are a builder call.

`Orchestrator` takes a `GuardedFetch` where it now takes a `UrlGuard` and an `HttpFetcher`. `fetchArticle` asks the helper for the page, keeps the entry URL and the history in local variables, and runs encoding, extraction, and purification on the page as it does today.

`OrchestratorFactory` builds one `FetchPolicy`, passes it to `HttpFetcher`, and passes the same guard, fetcher, and logger to `GuardedFetch`. The cap is stored on the policy for ticket 02. This ticket's helper does not read it.

`PipelineSuccess` gains `originalUrl` and `hopHistory`. `originalUrl` is the entry URL. The final URL is `document.sourceUrl`. There is no second copy of the final URL. `hopHistory` is the list of canonical request URLs left behind, in order, excluding the landing page. This ticket stores `[]`. The hop count is `count($hopHistory)`. Callers do not get a separate count field.

`PipelineNoContent` is unchanged. It keeps its existing page URL. It does not grow `originalUrl` or `hopHistory`.

## Data flow

```text
entryUrl
  → GuardedFetch::fetch
      → UrlGuard::guard(entryUrl)
      → HttpFetcher::fetch(SafeFetchTarget)     one GET, redirects refused
      → GuardedPage { page, originalUrl: entryUrl, hopHistory: [] }
  → EncodingNormalizer, ArticleExtractor, HtmlSanitizer
  → PipelineSuccess { document, warnings, originalUrl, hopHistory }
     or PipelineNoContent, which ignores originalUrl and hopHistory
```

A redirect, a transport error, a bad content type, or an oversized body still throws from `HttpFetcher`. `Orchestrator` wraps that as `OrchestratorError::Fetch`, as today. The thrown failure has no entry URL and no hop history.

A URL the guard rejects still throws as `OrchestratorError::UrlGuard`. No page is fetched.

An invalid cap throws from `FetchPolicy` while the fetcher is built. `fetchArticle` is not called.

## Errors

This ticket adds no failure kind. Cap 0 and a `3xx` stay `HttpFetcherError::Redirect`. A cap from 1 to 5 also stays that failure until ticket 02 follows hops. Ticket 01 must not follow, whatever the cap is.

## Logging

`GuardedFetch` writes the existing info lines `URL accepted` and `Page fetched` on the orchestrator logger, in that order, after each of those steps succeeds. `Orchestrator` does not write them again. The later stage lines stay on `Orchestrator`.

## Tests

The pipeline harness builds a `GuardedFetch` around the real guard and the queued fetcher, and passes that helper to `Orchestrator`. Article results assert `originalUrl`, `document.sourceUrl`, and `hopHistory`. Empty results assert those new fields are absent. A `3xx` still fails as today's redirect failure and does not consume a second queued response. The invalid-cap cases construct a `FetchPolicy` and perform no request.

One direct test of `GuardedFetch` shows one guard, one fetch, an empty history, and the entry URL unchanged. It does not run extraction.

## Ticket 02, not built here

Ticket 02 is designed in [FollowRedirectHops.md](FollowRedirectHops.md). The notes below are the ticket 01 sketch and are not the ticket 02 contract.

The helper grows the loop. Each hop resolves `Location` against the current canonical URL, guards it, pins it, and performs one GET. A followed hop appends that hop's canonical request URL to `hopHistory` before the next request. The landing page is not appended. The article result's fields do not move. An empty result still does not receive them.

The new failure for a hop past the cap, and the rule that a `300`, a `304`, or a missing `Location` stay today's redirect failure, are ticket 02.

## Foundation revision

When this design is accepted, `packages/article-reader/doc/features/0002-foundation/design/Orchestrator.md` changes in two places. The illegal state "Following redirects or retrying timeouts inside Orchestrator" becomes "Retrying timeouts inside Orchestrator". The decision "Redirects — fail closed on `3xx`" becomes "Redirects — `GuardedFetch` owns the hop loop; this ticket does not follow; `HttpFetcher` stays one pinned GET".

## Left to the builder

- The class and property names marked above, if a closer name already exists in the namespace.
- The order of the new `PipelineSuccess` constructor parameters, as long as `originalUrl` and `hopHistory` are required and the history defaults to an empty list only when that default cannot hide a forgotten value. Prefer required parameters.
- How `PipelineHarness` grows the helper argument, without changing what the existing scenarios assert except for the new fields on article results.
