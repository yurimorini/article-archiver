# Article reader

The library that fetches a page and purifies its body.

## Language

**Entry URL**:
The URL string the caller passed to the fetch.
_Avoid_: source URL, final URL, request URI

**Final URL**:
The canonical URL of the page whose body is extracted. Relative links in that body resolve against it.
_Avoid_: entry URL, original URL

**Hop count**:
How many redirect hops were followed to produce the final URL. It is the length of the hop history. Zero means the final URL is the canonical form of the entry URL.
_Avoid_: redirect chain, status code

**Hop history**:
The list of URLs left behind before the final page, in order. It does not include the landing page. An empty list means no redirect hop was followed.
_Avoid_: redirect chain, source URL

**Redirect hop**:
One HTTP response with status 301, 302, 303, 307, or 308 whose Location header names the next page. A 300 or a 304 is not a hop.
_Avoid_: meta refresh, script redirect, canonical URL
