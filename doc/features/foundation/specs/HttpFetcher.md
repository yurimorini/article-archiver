# HttpFetcher

This document is both a **design spec** (what to implement) and a **walkthrough** of why HttpFetcher exists and how data moves through it. Terms such as DNS pinning and Content-Type gates are introduced before they are used in APIs.

## Where HttpFetcher sits

The larger pipeline turns a remote page into cleaned article HTML (`[feature.md](../feature.md)`):

```text
input URL string
  → UrlGuard
  → HttpFetcher       (this document)
  → EncodingNormalizer
  → ArticleExtractor
  → HtmlSanitizer
  → article HTML
```

Composition root: `[Orchestrator.md](Orchestrator.md)` wires these stages; it is not a sixth processing stage.

HttpFetcher is the **second** stage. It performs a single pinned HTTP GET with hard limits. It does **not** normalize character encoding (that is `EncodingNormalizer`) and does **not** follow redirects (MVP policy).

```text
SafeFetchTarget → HttpFetcher → FetchedPage { body, contentType, … }
```

---

## Problem: download is untrusted I/O

Even after UrlGuard, the remote server can still:

| Behaviour | Why it is a problem |
|-----------|---------------------|
| Hang forever | Workers / CLI stuck without timeouts |
| Redirect to `http://127.0.0.1/` | SSRF via `Location` if redirects are followed blindly |
| Return a multi‑GB body | Memory exhaustion (OOM) |
| Return `application/pdf` / binary | Nonsense input for Readability |
| Resolve the hostname again | DNS rebinding / TOCTOU if pinning is skipped |

HttpFetcher’s job: turn a **`SafeFetchTarget`** into either a **bounded HTML-ish byte payload plus HTTP metadata**, or a **typed failure**. Downstream must not receive an unbounded stream or a bare `string` URL.

---

## Threat detail: DNS pinning (consumed, not re-done)

UrlGuard already resolved and validated public IPs and stored them on `SafeFetchTarget`. HttpFetcher **must pin** the TCP connect to those IPs:

```text
CURLOPT_RESOLVE = ["{host}:{port}:{ip1,ip2,…}"]
```

```php
'curl' => [
    CURLOPT_RESOLVE => $safe->curlResolveEntries(),
],
```

- Request URL stays `$safe->requestUri` (hostname for TLS SNI / `Host`).
- TCP goes only to the already-checked IPs.
- Requires Guzzle’s **cURL** handler for the pin to take effect. Stream handler (and handlers that ignore `curl` options) cannot apply `CURLOPT_RESOLVE`.

**MVP posture:** production `createDefaultClient()` uses the default cURL handler (`ext-curl` is a hard Composer requirement). We **assume** pinning works there. If the active handler cannot honour `CURLOPT_RESOLVE` (injected non-cURL client, unusual stack), HttpFetcher still attaches the resolve entries, **continues the request**, and logs a **warning** that DNS pinning was skipped — it does **not** fail closed on that alone. Orchestrator / ops can treat the warning as a configuration smell.

Refs: [UrlGuard.md](UrlGuard.md) (DNS / TOCTOU), [Foundational Research.md](../context/Foundational%20Research.md).

---

## Policy: no redirects (MVP)

**Do not follow redirects.** Configure Guzzle with `allow_redirects => false` (equivalent to “cap at zero follows” / a single request).

**Why:**

- A `302` to an internal host is a classic SSRF bypass if only the first URL was guarded.
- Re-running `UrlGuard` on every hop is correct but heavier; for MVP we refuse the hop instead.
- One request → one pin → simpler types and tests.

| Response | Behaviour |
|----------|-----------|
| `2xx` | Continue with gates (Content-Type, size, body read) |
| `3xx` | Fail closed → `HttpFetcherError::Redirect` (do not fetch `Location`) |
| `4xx` / `5xx` | Fail closed → `HttpFetcherError::HttpStatus` (explicit status check; see below) |

If redirect following is added later, each `Location` **must** go through `UrlGuard` again and be re-pinned before the next GET. That is out of scope for this MVP.

---

## Design principle: parse, don’t validate

HttpFetcher **parses** `SafeFetchTarget` → `FetchedPage`.

- Input is already a trusted fetch plan (not a raw `string`).
- Output carries **evidence** of what was downloaded: raw body bytes, Content-Type header value, status, final request URI used.
- Encoding is **not** proved here. `FetchedPage::$body` is opaque bytes (PHP `string`); charset may be undeclared or wrong. `EncodingNormalizer` parses that into `Utf8Html`.

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- [UrlGuard.md](UrlGuard.md) (same principle one stage earlier)

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `HttpFetcher` | Service | Build/use Guzzle client, pin, GET once, enforce gates, map errors |
| `FetchPolicy` | Value object | Immutable timeouts, size cap, headers defaults (no encoding knobs) |
| `FetchedPage` | Value object | Bounded response body + Content-Type (+ status / request URI) |
| `ClientInterface` | Collaborator (Guzzle) | Optional inject for tests; production client from internal factory |
| `HttpFetcherException` + `HttpFetcherError` | Error model | One exception type; enum says *which kind* of failure |

**Why HttpFetcher is not a DTO:** it runs I/O and policy. The DTO-like pieces are `FetchPolicy` and `FetchedPage`.

**Why no encoding middleware on the client:** Approach 3 — keep transport and charset as separate stages. Content-Type is read at the fetch gate and again by the normalizer; see [EncodingNormalizer.md](EncodingNormalizer.md).

**Why not a Builder for policy:** MVP has a small fixed set of knobs. Named args on `FetchPolicy` are enough. Introduce a builder only if configuration surface grows a lot.

---

## Optional collaborators, production default

Production almost always wants an internal Guzzle client with fixed defaults from `FetchPolicy`, plus a PSR-3 logger (pinning warning; optional Guzzle debug). Tests want `MockHandler` without the network.

```text
public function __construct(
    ?FetchPolicy $policy = null,
    ?ClientInterface $client = null,
    ?LoggerInterface $logger = null,
) {
    $this->policy = $policy ?? new FetchPolicy();
    $this->logger = $logger ?? new NullLogger();
    $this->client = $client ?? $this->createDefaultClient($this->policy);
}
```

| Call site | Usage |
|-----------|--------|
| CLI / normal library use | Factory passes shared logger: `new HttpFetcher($policy, null, $logger)` |
| Unit tests | `new HttpFetcher($policy, $clientWithMockHandler, $logger)` — no real HTTP |
| Custom transport | Inject any `ClientInterface`; if it cannot apply `curl` options, expect a pin-skipped warning |

**Policy vs collaborators:** timeouts / sizes / `debug` live on `FetchPolicy`. Guzzle client and logger are constructor collaborators (same split as ArticleExtractor).

**Internal factory (`createDefaultClient`) must:**

1. Use the default cURL handler (pinning).
2. Apply policy timeouts, default headers (`User-Agent`, `Accept`, …).
3. Set `allow_redirects` to `false`.
4. Set **`http_errors` to `false`** so `4xx`/`5xx` return as responses and are mapped locally to `HttpFetcherError::HttpStatus` (never rely on Guzzle throwing for HTTP status).
5. **Not** push `GuzzleTranscoder` or any charset middleware.
6. **Not** expose encoding configuration on the public API.
7. When `FetchPolicy::$debug` is true, attach Guzzle’s log middleware (or equivalent) so request/response summaries go to the injected PSR-3 logger at **`debug`** level. When `debug` is false, do not spam transfer detail (pinning warning still uses `warning` when needed).

Per-request options (pin via `CURLOPT_RESOLVE`, `stream => true`) are applied inside `fetch()`, not baked into the client constructor alone.

---

## Error model: one exception, tagged kind

Failures abort the pipeline for this URL (**fail closed**). The orchestrator catches a single type and branches on an enum—same shape as `UrlGuardException`.

```text
enum HttpFetcherError
{
    case Transport;
    case HttpStatus;
    case Redirect;
    case ContentType;
    case BodyTooLarge;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Transport => 'HTTP transport failed (timeout, connect, or network error)',
            self::HttpStatus => 'HTTP response status is not successful',
            self::Redirect => 'HTTP redirect is not followed',
            self::ContentType => 'Response Content-Type is not an allowed HTML type',
            self::BodyTooLarge => 'Response body exceeds the configured size limit',
        };
    }
}

final class HttpFetcherException extends \RuntimeException
{
    public function __construct(
        public readonly HttpFetcherError $error,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $error->defaultMessage(),
            0,
            $previous,
        );
    }
}
```

| Case | Plain meaning | Who detects it |
|------|---------------|----------------|
| `Transport` | Timeout, connect failure, other network / Guzzle transfer errors | Guzzle; wrapped with `$previous` |
| `HttpStatus` | Non-success status when not a redirect (e.g. 404, 500) | Explicit status check after response (`http_errors` is false) |
| `Redirect` | `3xx` (redirects disabled) | Status check after response |
| `ContentType` | Missing or non-HTML Content-Type | Local gate on headers |
| `BodyTooLarge` | `Content-Length` over cap, or streamed body over cap | Local gate while reading |

Throw sites may pass a more specific `$message` (status code, Content-Type seen, byte count). Empty `$message` → `$error->defaultMessage()`.

**Why not separate exception classes:** recovery is the same (do not continue to encoding / extraction); only logging / exit codes may differ by `$e->error`.

**Why `Redirect` is its own kind:** it is an intentional product policy (not a generic HTTP error). Callers may want a distinct exit code or message (“URL redirected; refusing to follow”).

Encoding failures belong to `EncodingNormalizer`, not this enum.

---

## Public API (implementation surface)

### `FetchPolicy`

Immutable. Self-validating construction (reject non-positive timeouts / maxBytes, etc.).

| Property | Type | Default (suggested) | Meaning |
|----------|------|---------------------|---------|
| `timeoutSeconds` | `int` | `10` | Total request timeout (**seconds**) |
| `connectTimeoutSeconds` | `int` | `5` | Connect timeout (**seconds**) |
| `maxBytes` | `int` | `5_000_000` | Hard cap on body size |
| `userAgent` | `string` | `LogRead/0.1` | Outgoing `User-Agent` (override via `FetchPolicy` / `PipelineOptions` when a contact URL is known) |
| `accept` | `string` | prefer `text/html` … | Outgoing `Accept` |
| `debug` | `bool` | `false` | When true, Guzzle transfer detail → PSR-3 `debug` on the HttpFetcher logger |

No redirect max (redirects are off). No charset / transcoder options.

```text
final readonly class FetchPolicy
{
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 5,
        public int $maxBytes = 5_000_000,
        public string $userAgent = 'LogRead/0.1',
        public string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        public bool $debug = false,
    ) {
        // assert positives / non-empty strings
    }
}
```

### `HttpFetcher`

```text
final class HttpFetcher
{
    private FetchPolicy $policy;
    private ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(
        ?FetchPolicy $policy = null,
        ?ClientInterface $client = null,
        ?LoggerInterface $logger = null,
    ) { … }

    /**
     * @throws HttpFetcherException
     */
    public function fetch(SafeFetchTarget $target): FetchedPage
}
```

**Logger (always injectable; default `NullLogger`):**

| Event | Level | When |
|-------|-------|------|
| DNS pin skipped (handler cannot apply `CURLOPT_RESOLVE`) | `warning` | Detect before/at request; include `requestUri` / host in context |
| Guzzle request/response summary | `debug` | Only when `FetchPolicy::$debug === true` |

Pinning warning is independent of `debug`. Factory always passes the shared pipeline logger so production ops see pin skips even when fetch debug is off.

Do **not** accept a raw URL string. Orchestrator passes `$guardResult->safe`.

### `FetchedPage`

Immutable result of a successful fetch gate. Constructible from `HttpFetcher` (package-private or factory used only there).

| Property | Type | Meaning |
|----------|------|---------|
| `body` | `string` | Raw **decoded** response bytes (may be non-UTF-8). Size ≤ `maxBytes` |
| `contentType` | `string` | Full `Content-Type` header line (may include `charset=`) |
| `statusCode` | `int` | HTTP status (successful `2xx`) |
| `requestUri` | `string` | URI that was requested (`SafeFetchTarget::$requestUri`) |

```text
final readonly class FetchedPage
{
    public function __construct(
        public string $body,
        public string $contentType,
        public int $statusCode,
        public string $requestUri,
    ) {}
}
```

**Invariants on success:**

- `statusCode` is `2xx` (including empty-body successes such as `200` with `""` or `204` when Content-Type still passes the gate — empty body is **not** a fetch error; ArticleExtractor maps it to soft `NoContent`).
- `contentType` matched the HTML allowlist at fetch time.
- `strlen($body) <= maxBytes` on **decoded** bytes.
- Encoding is **not** an invariant — that is `Utf8Html` after `EncodingNormalizer`.

**Why keep `contentType` on the VO:** EncodingNormalizer needs the header charset parameter; logging and debugging need the declared type. Reading Content-Type “in more than one place” is intentional: fetch uses it as a **gate**, normalizer uses it as a **charset hint**.

---

## Logical flow of `fetch()`

```text
fetch($target)
  │
  ├─ Build per-request options:
  │     curl CURLOPT_RESOLVE ← $target->curlResolveEntries()
  │     stream ← true
  │     (client already: timeouts, allow_redirects=false, headers, http_errors=false)
  │
  ├─ If handler cannot honour CURLOPT_RESOLVE
  │     → logger->warning(… pin skipped …)  // still continue
  │
  ├─ $client->get($target->requestUri, $options)
  │     └─ Guzzle transfer / timeout / connect failure
  │           → HttpFetcherError::Transport  (+ $previous)
  │
  ├─ Status is 3xx
  │     → HttpFetcherError::Redirect
  │
  ├─ Status is not 2xx
  │     → HttpFetcherError::HttpStatus
  │
  ├─ Content-Type gate (header line)
  │     allow: text/html, application/xhtml+xml (prefix match, case-insensitive)
  │     empty or other → HttpFetcherError::ContentType
  │
  ├─ If Content-Length present and > maxBytes
  │     → HttpFetcherError::BodyTooLarge  (before reading body)
  │
  ├─ Stream-read body in chunks; if strlen > maxBytes
  │     → HttpFetcherError::BodyTooLarge
  │
  └─ success → FetchedPage(body, contentType, statusCode, requestUri)
```

### Content-Type gate (fetch)

- Prefer types suitable for article HTML: `text/html`, `application/xhtml+xml`.
- Match on the MIME type prefix before parameters (ignore `charset=` here for the allow/deny decision).
- **Why:** avoid feeding PDF/JSON/binary into the HTML pipeline ([Foundational Research.md](../context/Foundational%20Research.md) §3.1).

Charset inside Content-Type is **not** converted here; it is carried forward for EncodingNormalizer.

### Body size gate

Two layers ([Foundational Research.md](../context/Foundational%20Research.md) §3.2):

1. `Content-Length` present and `> maxBytes` → abort before read (**skip or treat as advisory when `Content-Encoding` is present** — compressed length is not the decoded size).
2. While streaming, abort if accumulated **decoded** body size `> maxBytes`.

`maxBytes` applies to the **decoded** payload bytes collected into `FetchedPage::$body` (after Guzzle content decoding). Streaming exists to **enforce that cap**, not to stream into Readability.

---

## Testing guidance

### Approach

- Unit-test `HttpFetcher` with a Guzzle `Client` whose handler is `MockHandler` (+ optional history middleware). No real network.
  - Official pattern: [Guzzle testing / MockHandler](https://docs.guzzlephp.org/en/stable/testing.html).
- Inject that client via the optional constructor argument; keep production factory untested or covered lightly.
- Prefer PHPUnit data providers grouped by expected `HttpFetcherError` / success.
- On success, assert `FetchedPage` fields and that body length ≤ policy max.
- With history middleware, assert the request used `$target->requestUri` and that curl resolve entries were passed when using a real Curl handler path (MockHandler may not exercise cURL options—document what you can assert).
- When testing with `MockHandler`, a pin-skipped `warning` is expected if the test logger records it; production cURL path should not emit that warning.
- Cover `FetchPolicy::$debug === true` lightly: logger receives `debug` lines for the transfer (exact format may follow Guzzle’s MessageFormatter).

### Success → `FetchedPage`

| Case | Mock response | Notes |
|------|---------------|-------|
| HTML UTF-8 declared | `200`, `Content-Type: text/html; charset=utf-8`, small body | body opaque; charset still for next stage |
| XHTML type | `application/xhtml+xml` | allowed |
| No Content-Length | chunked-style body under cap | stream path |
| Exact maxBytes | body length == maxBytes | success |

### Failures

| Case | Mock / setup | Expected kind |
|------|--------------|---------------|
| Connect / timeout style error | queued `ConnectException` / `RequestException` | `Transport` |
| `404` / `500` | status 404/500 | `HttpStatus` |
| `301` / `302` with `Location` | status 3xx | `Redirect` (must not follow) |
| `Content-Type: application/pdf` | 200 + PDF type | `ContentType` |
| Empty Content-Type | 200 + no header | `ContentType` (fail closed) |
| `Content-Length` over max | header only | `BodyTooLarge` |
| Body grows past max while reading | stream without length or lying length | `BodyTooLarge` |

### Policy VO

- Unit-test `FetchPolicy` construction rejects invalid values (zero/negative timeouts or maxBytes) without HTTP.

---

## Out of scope for HttpFetcher

These belong to other stages or a later MVP:

- Character encoding detection / conversion (`EncodingNormalizer` → `EncodingOutcome` / `Utf8Html`).
- Following redirects / re-guarding `Location` hops.
- Readability extraction, HTMLPurifier.
- `robots.txt`, rate limiting ([Foundational Research.md](../context/Foundational%20Research.md) §2.4).
- Exposing or configuring charset middleware on the Guzzle stack.
