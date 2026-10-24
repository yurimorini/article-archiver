# 02: Follow redirect hops up to the cap

**Feature:** [0003-redirects](../feature.md)

**Contract:** [redirect-hops.md](../specs/redirect-hops.md)

**What to build:** A caller sets a hop cap from 1 to 5 and the fetch follows that many redirect hops. A redirect hop is status 301, 302, 303, 307, or 308 with a Location. Each next page is resolved against the current hop, checked, and DNS-pinned before it is fetched. The HTTP client does not follow a redirect on its own. The article comes from the last 2xx page. The article result carries the entry URL, the final URL, and the hop history. An empty result does not gain the entry URL or the hop history. A further redirect hop after the cap is spent is a new failure, distinct from today's redirect failure. A Location the guard rejects fails as a URL rejection. No earlier page is returned when a later hop fails.

**Blocked by:** [01 — Report the entry URL, the final URL, and the hop count](01-report-entry-and-final-url.md)

**Status:** ready-for-agent

**Type:** enhancement

- [ ] A cap from 1 to 5 follows a chain that lands on a 2xx within the cap. The hop history lists the URLs left behind and does not include the landing page. The hop count is the length of that list. A 2xx on the last allowed hop yields an article whose history has that length.
- [ ] A relative Location is resolved against the URL of the current hop before the next check. Another host, scheme, or port is fetched when the guard accepts it, with a new DNS pin for that hop.
- [ ] A single request does not follow a redirect by itself. The body of a redirect response is not extracted. Content type and size apply only to the last 2xx.
- [ ] A further redirect hop after the cap is spent fails with a failure distinct from today's redirect failure. No next page is fetched.
- [ ] A 300, a 304, or a 301, 302, 303, 307, or 308 with no Location, including an empty Location, fails with today's redirect failure. That stays true when the cap is already spent.
- [ ] A Location the guard rejects fails as the same URL rejection as a rejected entry URL.
- [ ] A later hop that answers 404 or 500, fails in transport, has a disallowed content type, or exceeds the size limit fails with that existing failure. No earlier page is returned.
- [ ] An empty result after one or more hops does not carry the entry URL or the hop history. A landed page is returned with no check that it is the same article. A redirect loop stops at the cap. No cookies are sent.
- [ ] The sentences are English.
- [ ] Quality checks pass.

## Comments

Ticket 01 left the hop cap on `FetchPolicy` and an empty `hopHistory` on the article result. `GuardedFetch::fetch` still performs one guarded GET and throws on a redirect. It does not read `maxRedirectHops`. This ticket fills that method with the hop loop. The article-result fields stay where they are.
