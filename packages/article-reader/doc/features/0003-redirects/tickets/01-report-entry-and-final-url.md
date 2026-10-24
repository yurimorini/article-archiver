# 01: Report the entry URL, the final URL, and the hop count

**Feature:** [0003-redirects](../feature.md)

**Contract:** [redirect-hops.md](../specs/redirect-hops.md)

**What to build:** A caller fetches one URL and, when the fetch yields an article, receives the entry URL, the final URL, and the hop history. The entry URL is the string the caller passed. The final URL is the canonical request URL of the page whose body was extracted, and it is the body source URL. The hop history is the list of URLs left behind. This ticket follows no redirect hop, so the list is empty and the hop count is zero. An empty result does not gain the entry URL or the hop history. It keeps the single page URL it already has. A thrown failure carries none of those facts. The hop cap is part of the fetch configuration: the default is zero, the legal values are the integers 0 through 5, and any other integer is rejected before a request. A cap of zero keeps today's redirect failure.

**Blocked by:** None (can start immediately).

**Status:** done

**Type:** enhancement

- [x] A hop cap below 0 or above 5 is rejected when the fetch is configured, and that rejection performs no request.
- [x] The hop cap defaults to 0. An explicit cap of 0 is accepted.
- [x] With cap 0, a redirect hop fails with today's redirect failure. No next page is fetched.
- [x] An article result carries the entry URL unchanged, the final URL as the canonical request URL of the fetched page, and an empty hop history. The body source URL is the final URL. The hop count is the length of the history.
- [x] When the caller string differs from that canonical URL only by canonicalization, the hop history is still empty and the final URL is the canonical form.
- [x] An empty result does not carry the entry URL or the hop history. It keeps the single page URL it already has.
- [x] A lossy encoding of a page that still yields an article stays a warning on that article result, and the URL facts remain.
- [x] A thrown failure carries neither the entry URL, nor the final URL, nor the hop history.
- [x] The sentences are English.
- [x] Quality checks pass.

## Comments

Landed on `import-command` at `e8ec443`. `FetchPolicy::$maxRedirectHops` accepts 0 through 5 and defaults to 0. An integer outside that range is rejected when the policy is built. `GuardedFetch` checks the caller string and downloads once. It returns that string as `originalUrl` and an empty `hopHistory`. `PipelineSuccess` stores both. The final URL stays `document.sourceUrl`. `PipelineNoContent` and a thrown failure do not carry them. A `3xx` stays today's redirect failure, and a second queued response is not consumed. The helper does not read the hop cap, so a cap above 0 still does not follow. Following is ticket 02.

`./bin/test` passed for `article-reader` and `web-app` on that commit.
