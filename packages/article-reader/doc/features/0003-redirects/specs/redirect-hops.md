**Status:** ready-for-agent

## Problem Statement

A caller of the library has pages that were saved long ago and now answer with a redirect to another page. The fetch refuses every `3xx`. The caller gets no article, and the result does not say where the page moved. Following that redirect inside the HTTP client would skip the URL check and the DNS pin, so a public page could send the next request to a private address.

## Solution

The caller sets a maximum number of redirect hops for a fetch. The default is zero, which keeps today's refusal. A value from 1 to 5 follows that many redirect hops. Each hop is checked and DNS-pinned again before it is fetched. The HTTP client does not follow a redirect by itself.

An article result reports the entry URL, the final URL, and the hop history. The hop count is the length of that history. An empty result does not gain those facts. It keeps the single page URL it already has. A thrown failure carries none of them. The article, or the empty result, is taken from the last successful page. The library does not name an archive.

## User Stories

1. As a caller of the library, I want a page that redirects to another public page to yield that page's article, so that a saved URL which moved still produces an article.
2. As a caller of the library, I want the default fetch to follow no redirect hop, so that a caller who sets nothing keeps today's refusal.
3. As a caller of the library, I want to set the hop cap to zero explicitly, so that the fail-closed fetch is a choice I can write down.
4. As a caller of the library, I want to set the hop cap from 1 to 5, so that I choose how many redirect hops a fetch may follow.
5. As a caller of the library, I want a hop cap below 0 to be rejected when the fetch is configured, so that a fetch never starts with a negative cap.
6. As a caller of the library, I want a hop cap above 5 to be rejected when the fetch is configured, so that a fetch cannot be configured for a longer chain.
7. As a caller of the library, I want a rejected hop cap to perform no request, so that a bad configuration does not touch the network.
8. As a caller of the library, I want a redirect hop to be only status 301, 302, 303, 307, or 308 with a Location header, so that other responses are not treated as a next page.
9. As a caller of the library, I want a 300 to fail with today's redirect failure, so that a multiple-choice response is not followed.
10. As a caller of the library, I want a 304 to fail with today's redirect failure, so that a not-modified response is not a hop.
11. As a caller of the library, I want a 301, 302, 303, 307, or 308 with no Location to fail with today's redirect failure, so that a hop with no next page does not continue.
12. As a caller of the library, I want an empty Location to fail with today's redirect failure, so that a blank next page is not fetched.
13. As a caller of the library, I want a cap of zero and a first redirect hop to fail with today's redirect failure, so that "following is off" stays the failure I already handle.
14. As a caller of the library, I want a further redirect hop after the cap is spent to fail with a different failure, so that I can tell "following is off" from "the chain was longer than I allowed".
15. As a caller of the library, I want a 300, a 304, or a followable status with no Location to stay today's redirect failure even when the cap is already spent, so that the new failure means only that a real next hop was refused.
16. As a caller of the library, I want a 2xx after exactly as many hops as the cap allows to succeed, so that a chain which lands on the last allowed hop is kept.
17. As a caller of the library, I want the hop count to be the number of redirect hops that were fetched, so that a direct 2xx is zero and one followed hop is one.
18. As a caller of the library, I want a relative Location resolved against the URL of the current hop before the next check, so that a path-only redirect still names a page.
19. As a caller of the library, I want the next page checked and DNS-pinned before it is fetched, so that a redirect cannot open a private address.
20. As a caller of the library, I want a Location the guard rejects to fail as a URL rejection, so that a forbidden target is not reported as a redirect failure.
21. As a caller of the library, I want that URL rejection to be the same kind as a rejected entry URL, so that a bad hop and a bad first URL are one failure.
22. As a caller of the library, I want the next page to use another host, scheme, or port when the guard accepts it, so that a page which moved sites can still be fetched.
23. As a caller of the library, I want each hop to use a new DNS pin for that hop's host, so that a changed host does not reuse the previous addresses.
24. As a caller of the library, I want the HTTP client to leave redirects unfollowed on a single request, so that a hop cannot skip the guard.
25. As a caller of the library, I want no body extracted from a redirect response, so that the redirect page itself is never the article.
26. As a caller of the library, I want HTML and size limits applied only to the last 2xx, so that a redirect response is not judged as an article.
27. As a caller of the library, I want the article taken from the last 2xx page, so that the body is the page I landed on.
28. As a caller of the library, I want no check that the landed page is the same article as the entry URL, so that a move to a different article is still returned.
29. As a caller of the library, I want a later hop that answers 404 or 500 to fail with today's status failure, so that an error page is not stored and no earlier page is substituted.
30. As a caller of the library, I want a later hop that fails in transport to fail with today's transport failure, so that a dropped connection does not return an earlier page.
31. As a caller of the library, I want a last page whose content type is not HTML to fail with today's content-type failure, so that a redirect to a file is refused.
32. As a caller of the library, I want a last page over the size limit to fail with today's size failure, so that the cap still protects the landed page.
33. As a caller of the library, I want the entry URL on an article result to be the string I passed, unchanged, so that I still have the name I asked for.
34. As a caller of the library, I want the final URL to be the canonical request URL of the page whose body was extracted, so that it is the URL the guard already built for that hop.
35. As a caller of the library, I want the body source URL to be the final URL, so that relative links in the article resolve against the landed page.
36. As a caller of the library, I want the hop history on an article result, so that I can tell a redirect from a difference of canonicalization. The hop count is the length of that history.
37. As a caller of the library, I want a hop count of zero to mean the final URL is only the canonical form of the entry URL, so that a fetch with no hop is recognizable.
38. As a caller of the library, I want both URLs and the hop count when the landed page has an article, so that a success still names the entry and the landing.
39. As a caller of the library, I want an empty result to stay without the entry URL and without the hop history, so that those facts exist only on an article result.
40. As a caller of the library, I want a lossy encoding of the landed page to stay a warning on that finished result, so that a redirect does not turn a soft encoding problem into a failure.
41. As a caller of the library, I want a thrown failure to carry neither the entry URL, nor the final URL, nor the hop history, so that a failed fetch cannot be read as an article result.
42. As a caller of the library, I want a redirect loop to stop at the cap, so that a cycle cannot run without bound.
43. As a caller of the library, I want no cookies sent on any hop, so that a redirect to a login page is only the page I landed on.
44. As a caller of the library, I want two entry URLs which land on one final URL to stay two fetches, so that the library does not merge them into one archive.
45. As a caller of the library, I want an article result with no redirect to return both URLs and an empty hop history, so that the result shape does not depend on a hop having happened.

## Implementation Decisions

- A redirect hop is status 301, 302, 303, 307, or 308 with a Location header. A 300, a 304, a meta refresh, a script redirect, and a canonical link are not hops.
- The caller sets the hop cap on the fetch configuration. The legal values are the integers 0 through 5. The default is 0. Any other integer is rejected while the configuration is built, before a request.
- Cap 0 does not follow. The first redirect hop fails with the existing redirect failure.
- A redirect hop is followed only while the number already followed is below the cap. The next request is a new guarded, DNS-pinned GET. The HTTP client is configured not to follow redirects.
- A relative Location is resolved against the URL of the current hop, then passed to the guard as an absolute URL. The guard's existing rules decide that URL. A rejection is the existing URL-rejection failure, including a private address, a disallowed scheme, or credentials in the URL.
- When the cap is already spent and the response is another redirect hop, the fetch fails with a new failure, distinct from the existing redirect failure. A 300, a 304, or a followable status with no Location keeps the existing redirect failure, including when the cap is already spent.
- The body of a redirect response is not read. Content type and size apply to the last 2xx only. A non-2xx last page, a transport error, a disallowed content type, and an oversized body keep the failures the fetch already uses. No earlier page is returned in their place.
- The entry URL is the caller string, unchanged. The final URL is the canonical request URL of the page whose body is extracted: the URL the guard built for that hop. The body source URL is the final URL. The hop history is the list of URLs left behind, in order, and it does not include the landing page. The hop count is the length of that list. The list is always present on an article result.
- An article result carries the entry URL, the final URL, and the hop history. An empty result does not. It keeps the single page URL it already has. A thrown failure carries none of the entry URL, the final URL, or the hop history.
- An empty hop history means no redirect hop was fetched. The final URL is then the canonical form of the single page, which can differ from the entry URL by that canonicalization alone.
- The library does not store an archive and does not merge two entry URLs that share a final URL.
- The hop loop is a helper the composition root calls. The helper holds the hop state. One pinned GET remains one guarded request. The foundation decision that the composition root does not follow redirects is revised because the composition root calls that helper.

## Testing Decisions

- Test the external behavior of one library fetch. A good test of an article shows the entry URL, the final URL, and the hop history, or it shows the failure kind. An empty result shows neither the entry URL nor the hop history. The test does not show which type owns the loop.
- One seam. Queue the HTTP responses and stub the address check, using the same pipeline harness the fetch tests already use. Do not open a socket and do not resolve DNS.
- Prove a chain only at that fetch. A separate single-request test may still prove that one pinned GET does not follow a redirect by itself.
- Cover cap 0, a cap of 1 through 5 that lands on a 2xx, a chain longer than the cap, a missing Location, a 300, a 304, a guard rejection on a later hop, a relative Location, a change of host, an empty result after a hop that carries neither the entry URL nor the hop history, and a direct 2xx with an empty hop history.
- An invalid cap is asserted where the configuration is built, and that case performs no request.

## Out of Scope

- Meta refresh, script redirects, and canonical links.
- Storing the final URL on an archive, and choosing the importer's hop cap. Eleanor's archive API keeps the default of zero. The importer's opt-in belongs to the import feature.
- A command flag on the article-reader command. The command will gain a way to set the cap later. Its shape is not part of this spec.
- Cookies, login detection, and a check that the landed page is the same article.
- Merging fetches that share a final URL.

## Further Notes

- Terms: entry URL, final URL, hop history, hop count, and redirect hop. They are defined in the package glossary.
- Revised: the entry URL and the hop history belong to the article result only. An empty result does not gain them.
- Decisions already recorded: hop cap defaulting to zero, entry URL distinct from final URL, the library not naming an archive, and opt-in being per caller.
- The import spec still requires the same write as the archive API. That spec changes in the import feature, when the importer's cap is chosen.
- Several Location values on one response were not discussed. This spec does not choose among them.
