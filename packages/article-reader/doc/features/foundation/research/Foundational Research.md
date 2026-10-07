# Research: remote HTML → cleaned article string

Algorithmic pipeline for fetching a remote page and producing a sanitized article HTML string.

Stack decisions captured from design discussion:


| Role                               | Library / tool                                           |
| ---------------------------------- | -------------------------------------------------------- |
| HTTP client                        | Guzzle                                                   |
| Charset → UTF-8                    | `fossar/guzzle-transcoder` (and/or manual cascade)       |
| SSRF URL validation + resolved IPs | `craftcms/url-validator`                                 |
| URL component split (host/port)    | PHP `parse_url` (League Uri not required for this flow)  |
| Main-content extraction            | `fivefilters/readability.php` (Mozilla Readability port) |
| XSS / markup allowlist             | `ezyang/htmlpurifier`                                    |


Out of scope for the MVP (add later if needed):

- `robots.txt` (relevant for automated crawlers, not one-shot user URLs)
- Persistent per-domain rate limiting (relevant for batch jobs)
- Headless browser (only if body is JS-rendered and missing from static HTML)

---



## End-to-end sequence

```text
input URL string
  → 1. Normalize & validate URL (incl. SSRF)
  → 2. HTTP fetch (Guzzle) with pin + limits
  → 3. Enforce Content-Type / body size
  → 4. Ensure UTF-8 body
  → 5. Extract article (Readability)
  → 6. Sanitize HTML (HTMLPurifier)
  → output: safe article HTML string
```

Each stage below lists sub-steps and **why**.

---



## 1. Normalize and validate the input URL

**Goal:** turn a raw string into a fetchable, non-internal destination before any network I/O to untrusted targets.

### 1.1 Trim

- Strip leading/trailing whitespace.
- **Why:** copy-paste and CLI args often include spaces/newlines that break parsing.



### 1.2 Parse into components

- Use `parse_url($url)` (or equivalent).
- Read at least: `scheme`, `host`, `port`, optionally `user` / `pass`.
- **Why:** you need host/port later for DNS pinning (`CURLOPT_RESOLVE`). A dedicated URI library (e.g. League Uri) is optional here; it does **not** replace SSRF checks.



### 1.3 Restrict scheme

- Allow only `http` and `https`.
- Reject `file:`, `ftp:`, `gopher:`, `data:`, etc.
- **Why:** other schemes are classic SSRF / local-file vectors or useless for HTML articles.



### 1.4 Require a real host

- Reject empty / missing host.
- **Why:** malformed URLs must fail closed.



### 1.5 Reject embedded credentials (recommended)

- If `user` / `pass` are present and you do not need them, reject.
- **Why:** credentials in URLs are rarely needed for public articles; they complicate logging and can hide abuse patterns.



### 1.6 SSRF validation (security-critical when URL is untrusted)

Use something like `craftcms/url-validator`:

1. Validate scheme/host against policy.
2. Resolve DNS for the hostname.
3. Reject if any resolved address is non-public, including at least:
  - loopback (`127.0.0.0/8`, `::1`)
  - private RFC1918 (`10/8`, `172.16/12`, `192.168/16`)
  - link-local (`169.254.0.0/16` — includes cloud metadata `169.254.169.254`)
  - equivalent IPv6 private / ULA / link-local ranges
  - IPv6 forms that embed/tunnel blocked IPv4 (mapped, etc.)
4. On success, obtain the **list of public IPs** used for pinning (next stage).

**Why SSRF matters:** if the caller controls the URL, your server becomes a proxy into localhost, LAN, or cloud metadata. Validating the hostname string alone is insufficient (`evil.com` can resolve to `127.0.0.1`).

**When you can soften this:** personal CLI where *you* always type the URL. Harden fully when URLs come from users, feeds, or APIs.

**Note:** `league/uri` / `parse_url` parse syntax only. They do **not** perform SSRF IP checks.

---



## 2. HTTP fetch (Guzzle)

**Goal:** download HTML with predictable limits and a pinned destination.

### 2.1 Client defaults

Configure at least:


| Option            | Typical value                      | Why                                                                        |
| ----------------- | ---------------------------------- | -------------------------------------------------------------------------- |
| `timeout`         | e.g. 10s                           | Avoid hung workers                                                         |
| `connect_timeout` | e.g. 5s                            | Fail fast on dead hosts                                                    |
| `User-Agent`      | identifiable product string        | Courtesy + debugging; do not spoof a full browser unless you have a reason |
| `Accept`          | prefer `text/html`                 | Signal intent; still verify response type                                  |
| `http_errors`     | true (or handle status explicitly) | Do not treat error pages as articles blindly                               |




### 2.2 DNS / connection pinning (security)

After SSRF validation returns `$ips`:

```text
CURLOPT_RESOLVE = ["{host}:{port}:{ip1,ip2,...}"]
```

Passed to Guzzle as:

```php
'curl' => [
    CURLOPT_RESOLVE => [
        $host . ':' . $port . ':' . implode(',', $ips),
    ],
],
```

- Keep the request URL as `https://hostname/...` so `Host` and TLS SNI stay correct.
- Force TCP to the **already validated** IPs.

**Why (“pin the connect”):** closes the DNS-rebinding / TOCTOU window:

```text
t0  validate(host) → public IP  ✓
t1  client re-resolves host → private IP  ✗  (without pin)
```

Pinning = connect to the IP you checked; do not trust a second resolve.

Requires Guzzle’s **cURL** handler (default on typical Linux installs). Stream handler cannot use `CURLOPT_RESOLVE`.

### 2.3 Redirects (security)

**MVP decision (see [HttpFetcher.md](../specs/HttpFetcher.md)):** do **not** follow redirects (`allow_redirects => false`). A `3xx` fails closed. This avoids re-running UrlGuard on every hop while still refusing SSRF via `Location`.

If redirect following is added later:

- Cap redirects (e.g. max 5).
- **Re-run SSRF validation (and pin) on every hop**, or disable auto-follow and handle `Location` manually.

**Why:** a public page can `302` to `http://127.0.0.1/` or metadata. Validating only the first URL is not enough.

### 2.4 What we deliberately skip in MVP


| Topic                   | Decision                | Why                                                                      |
| ----------------------- | ----------------------- | ------------------------------------------------------------------------ |
| `robots.txt`            | skip for one-shot fetch | Courtesy for crawlers; not a security control                            |
| Per-domain rate limiter | skip unless batch       | Overkill for single CLI fetches; add delay/limit when scraping many URLs |


---



## 3. Response gates (before trusting the body)

**Goal:** refuse non-HTML and unbounded payloads early.

### 3.1 Content-Type check

- Prefer `text/html` or `application/xhtml+xml`.
- **Why:** avoid feeding PDF/binary/JSON into Readability; reduces surprise and some resource abuse.



### 3.2 Max body size

Two layers:

1. If `Content-Length` is present and `> maxBytes` → abort before reading.
2. While reading the body, accumulate and abort if `strlen > maxBytes` (e.g. 2–5 MiB for articles).

**Why max body:**

- Readability needs the full document in memory anyway.
- Without a cap, a hostile or misconfigured server can OOM the process (`Content-Length` can lie or be absent).
- Streaming is used **to enforce the cap**, not to feed Readability incrementally.



### 3.3 Read strategy

- Stream chunks into a string until EOF or cap.
- Then pass one complete string downstream.

**Why not “true streaming” through the pipeline:** Mozilla-style Readability scores a full DOM; it is not a chunked article extractor.

---



## 4. Character encoding → UTF-8

**Goal:** one internal encoding for Readability, HTMLPurifier, storage, and output.

**Target UTF-8** for this project. Avoid UTF-8 as the *only* representation only when you must preserve original bytes (forensics) or speak a legacy egress charset — not the normal article pipeline.

### 4.1 Do not confuse headers


| Header                   | Meaning                                                         |
| ------------------------ | --------------------------------------------------------------- |
| `Content-Encoding`       | Compression (`gzip`, `br`) — usually handled by the HTTP client |
| `Content-Type; charset=` | Text encoding of the HTML                                       |




### 4.2 Detection priority (browser-like cascade)

1. **BOM** (byte signature at start) — check raw bytes, not `mb_detect_encoding`.
  - UTF-8 BOM: `EF BB BF` → treat as UTF-8; strip BOM before parsing (many tools dislike a leading BOM).
2. **HTTP** `Content-Type` **charset** — authoritative when present (wins over conflicting meta in HTML5 model).
3. `<meta charset>` **/** `http-equiv=Content-Type` — scan roughly the **first 1024 bytes** with a regex/prescan (no full DOM, no Purifier yet: chicken-and-egg).
4. **Validity check** — e.g. `mb_check_encoding($html, 'UTF-8')`.
5. **Guess last** — `mb_detect_encoding` (heuristic) or a chardet-style library; never as the only source of truth.

**Why header can disagree with meta:** misconfigured servers are common. Policy: trust HTTP charset when set; fall back to meta; verify bytes.

**Why not DOM/Purifier before charset:** you need encoding to interpret the document; meta sniffing is intentionally a byte-level prescan.

### 4.3 Library option used in this stack

**MVP (see [EncodingNormalizer.md](../specs/EncodingNormalizer.md)):** no Guzzle charset middleware. `EncodingNormalizer` owns the cascade and returns `EncodingOutcome` (`Ok` | `Degraded`) carrying `Utf8Html`.

Reuse pieces of `fossar/guzzle-transcoder` as a **library** (e.g. `ContentTypeExtractor` for header/meta), and convert with `Ddeboer\Transcoder\Transcoder` (its dependency)—not as HandlerStack middleware. Prefer HTTP charset over meta when both are present (HTML5-oriented policy); do not rely on `GuzzleTranscoder::convertResponse()` as-is for that precedence.

### 4.4 What `mb_detect_encoding` actually does

- Guesses from **byte patterns** among candidate encodings.
- Does **not** read HTTP headers or HTML meta as policy.
- Ambiguous for single-byte sets (latin1 / windows-1252). Use only as fallback after declared charset + UTF-8 validity checks.

---



## 5. Extract main content (Readability)

**Goal:** isolate title/body from chrome (nav, ads, footer), plus fused article metadata.

### 5.1 Parse

- Feed **complete UTF-8 HTML** into `fivefilters/readability.php`.
- Obtain article fields (title, content HTML, metadata as available).
- Check `hasContent()` (or equivalent) before treating extraction as success.



### 5.1b Metadata (JSON-LD / Open Graph / …)

Readability **already** extracts and normalizes metadata: Schema.org JSON-LD, Open Graph (`og:`*), Twitter cards, Dublin Core, and related `<meta>` tags are merged into flat fields (`title`, `excerpt`, `siteName`, `byline`, `publishedTime`, plus PHP `image` from `og:image` / `twitter:image`). First-non-empty wins inside the library; HTML entities are unescaped.

**Project policy:** consume those fused fields via `ArticleExtractor` / `PlainText` — do **not** add a second OG/JSON-LD scraper or expose raw `og:`* maps. Details: `specs/ArticleExtractor.md` (Metadata section).

### 5.2 What Readability is and is not


| Is                                            | Is not                                                               |
| --------------------------------------------- | -------------------------------------------------------------------- |
| Heuristic “reader view” content finder        | XSS sanitizer                                                        |
| Fused metadata from JSON-LD / og / other meta | Guarantee of which upstream tag “won”                                |
| Good at ignoring page chrome                  | Guarantee that no `iframe` / odd tags remain inside the winning node |
| Expects full HTML already in the response     | Executor of page JavaScript                                          |


**Why a separate sanitizer still follows:** Readability optimizes for readability, not a security allowlist.

### 5.3 Historical context (FiveFilters)

Classic Full-Text RSS flow: feed item URL → fetch page → Readability → rewrite feed with full article body. Same core stages as this library, minus feed I/O.

---



## 6. Sanitize HTML (HTMLPurifier)

**Goal:** make extracted HTML safe and predictable to store or display.

### 6.1 Run Purifier on Readability output

- Input encoding: UTF-8 (`Core.Encoding`).
- Doctype: use a value HTMLPurifier actually supports (e.g. `XHTML 1.0 Transitional` / HTML 4.01). `html5` **is not a valid** `HTML.Doctype` **value** in HTMLPurifier.



### 6.2 Default config (baseline)

With `HTMLPurifier_Config::createDefault()` and `HTML.Trusted = false`:

- Dangerous elements (`script`, `iframe`, `object`, `form`, …) are **not** in the effective allow set.
- URI validation is already active on URL-bearing attributes.
- Default allowed schemes include `http`, `https`, `mailto`, `ftp`, `nntp`, `news`, `tel`.
- `data:` and `file:` are **not** enabled by default (good).

**For this project, tighten URI schemes** to what articles need:

```text
http, https, mailto
```

**Why URI filter matters more than Tidy:** blocks `javascript:` and odd schemes in `href`/`src`. Tidy is optional HTML cleanup, not the core security control.

### 6.3 Optional: article allowlist (recommended when HTML is shown)

Defaults are already safer than “whatever Readability returned”, but still allow a wide transitional tag set (`font`, `center`, tables, …).

Optionally set `HTML.Allowed` to an article-oriented allowlist, e.g. headings, paragraphs, lists, links, images, basic emphasis, optional tables.

**Why “not everything Readability passes”:** defense in depth — extraction ≠ permission to render.

### 6.4 Cache

- `Cache.DefinitionImpl = null` is for debugging only; restore a real definition cache in production or Purifier rebuilds definitions every request.

---



## 7. Output

- Result: UTF-8 HTML string of the cleaned article fragment/body (`SafeDocument::$html`).
- Metadata (`title`, `excerpt`, `siteName`) is `PlainText`: persist `raw()`, embed in HTML via `html()`; do not run Purifier on these fields.
- If embedding into a larger HTML page, still use `PlainText::html()` for non-body fields; Purifier cleans the fragment only.

---



## Security checklist (condensed)


| Control                         | Stage        | Threat addressed                           |
| ------------------------------- | ------------ | ------------------------------------------ |
| Scheme allowlist `http`/`https` | URL validate | Non-HTTP SSRF / odd handlers               |
| SSRF IP checks after DNS        | URL validate | Localhost, LAN, cloud metadata             |
| DNS pin via `CURLOPT_RESOLVE`   | Fetch        | DNS rebinding between check and connect    |
| Re-validate redirect hops       | Fetch        | Open redirect into internal network        |
| Timeouts                        | Fetch        | Resource exhaustion / hang                 |
| Max body size (+ stream cap)    | Fetch        | Memory exhaustion                          |
| Content-Type gate               | Fetch        | Non-HTML abuse / nonsense input            |
| Identifiable User-Agent         | Fetch        | Operability / abuse attribution (courtesy) |
| Charset normalize to UTF-8      | Encoding     | Mojibake; Purifier/parser assumptions      |
| Readability                     | Extract      | Noise reduction (not XSS)                  |
| HTMLPurifier + URI schemes      | Sanitize     | XSS stored/reflected via article HTML      |
| Optional tag allowlist          | Sanitize     | Reduce unexpected markup surface           |


---



## Suggested implementation shape

Keep I/O, extraction, and sanitization as separate units (single responsibility):

```text
UrlGuard           → normalize + SSRF validate → SafeFetchTarget
HttpFetcher        → Guzzle GET (pin, timeouts, size, content-type); no redirects → FetchedPage
EncodingNormalizer → charset cascade → EncodingOutcome (Ok|Degraded + Utf8Html with sourceUrl)
ArticleExtractor   → Readability → ExtractResult (Ok + ReadableDocument | NoContent)
HtmlSanitizer      → HTMLPurifier policy → SafeDocument
Orchestrator       → wires the pipeline; UX for Degraded / NoContent / errors
```



## Provenance: `requestUri` / `sourceUrl` propagates on `FetchedPage` → `Utf8Html` → `ReadableDocument` → `SafeDocument`. Specs: `doc/features/foundation/specs/`.



## Deferred / later enhancements

1. Redirect-safe fetch loop with per-hop validate + pin.
2. Stricter `HTML.Allowed` once display requirements are clear.
3. `robots.txt` + polite delay when batch-crawling.
4. Headless render path only for known JS-heavy sites.
5. Persist raw response bytes separately if forensic round-trip is ever required (distinct from the UTF-8 working copy).

