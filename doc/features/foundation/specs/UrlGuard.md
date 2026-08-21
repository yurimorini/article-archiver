# UrlGuard

This document is both a **design spec** (what to implement) and a **walkthrough** of why UrlGuard exists and how data moves through it. Terms such as SSRF and DNS are introduced before they are used in APIs.

## Where UrlGuard sits

The larger pipeline turns a remote page into cleaned article HTML (`[feature.md](../feature.md)`):

```text
input URL string
  → UrlGuard          (this document)
  → HttpFetcher       (download with limits; see [HttpFetcher.md](HttpFetcher.md))
  → EncodingNormalizer (UTF-8; see [EncodingNormalizer.md](EncodingNormalizer.md))
  → ArticleExtractor
  → HtmlSanitizer
  → article HTML
```

Composition root: `[Orchestrator.md](Orchestrator.md)` wires these stages; it is not a sixth processing stage.

UrlGuard is the **first** stage. It must refuse unsafe or malformed destinations **before** any HTTP request to an untrusted host.

```text
UrlGuard → GuardResult { SafeFetchTarget, original } → HttpFetcher → FetchedPage → EncodingNormalizer → EncodingOutcome { Utf8Html }
```

---

## Problem: the input is untrusted

The caller passes a **string**. That string may be a CLI argument, an API field, or a redirect `Location` header. PHP’s type `string` does not mean “valid URL” or “safe to fetch”.

Examples of bad input:

| Input | Why it is a problem |
|-------|---------------------|
| `"  https://example.com  "` | Leading/trailing spaces break naive parsers |
| `"not a url"` | Not parseable as a URL |
| `"file:///etc/passwd"` | Would read a local file if the client followed the scheme |
| `"http://127.0.0.1/"` | Points at the machine running this program |
| `"http://169.254.169.254/"` | Cloud “metadata” endpoint (credentials/config on many hosts) |
| `"https://user:pass@evil.example/"` | Credentials in the URL; rarely needed for public articles |

UrlGuard’s job: turn that string into either a **structured, trusted fetch plan**, or a **typed failure**. Downstream code should not receive a bare `string` and decide again whether it is safe.

---

## Threat: SSRF (Server-Side Request Forgery)

**SSRF** means: an attacker tricks *your* server into making a request they chose, often toward targets the attacker cannot reach directly from the internet.

Typical abuse:

1. User (or feed, or API client) supplies `http://127.0.0.1:8080/admin`.
2. Your process runs on a server or laptop with access to localhost / LAN / cloud metadata.
3. Your HTTP client fetches that URL → you become a proxy into internal networks.

So “the hostname looks like a normal website” is not enough. We must also ask: **after we look up the hostname, which IP addresses would we connect to?** If any of them are private, loopback, or link-local (including cloud metadata ranges), we refuse.

This project uses the library **craftcms/url-validator** for those IP / hostname policy checks. UrlGuard wraps it behind a small interface (`SsrfUrlValidator`) so tests can fake it and so vendor exceptions stay isolated.

---

## Threat detail: DNS and why we keep the IPs

**DNS** (Domain Name System) maps a hostname such as `www.example.com` to one or more **IP addresses** (e.g. `93.184.216.34`). Looking up that mapping is a network operation (I/O). It can fail (no such host, timeout), and the answer can change over time.

Flow without care:

```text
t0  validate hostname → resolves to a public IP  ✓
t1  HTTP client resolves the hostname again → now a private IP  ✗
```

That gap is a classic **DNS rebinding / TOCTOU** issue: check and use are two different lookups.

**Mitigation used here (DNS pinning):**

1. Resolve and validate IPs **once** during UrlGuard.
2. Store those IPs on the result (`SafeFetchTarget::$ips`).
3. When fetching, tell cURL to connect to **those** IPs while still using the hostname in the URL (so TLS certificate / `Host` header stay correct).

cURL option shape:

```text
CURLOPT_RESOLVE = ["{host}:{port}:{ip}", …]
```

Example: request URL remains `https://www.example.com/path`, but TCP goes to the already-checked public IP(s).

UrlGuard therefore returns not only a cleaned URL, but also **host**, **port**, and **ips** so `HttpFetcher` can pin. Returning only “URL is OK” as a boolean would throw away the IPs and force a second resolve.

---

## Design principle: parse, don’t validate

**Validate** = check a condition and discard the evidence (`true`/`false` or throw with no richer type).

**Parse** = check and produce a **more precise type** that carries the proof forward.

UrlGuard **parses** `string` → `GuardResult`. `HttpFetcher` accepts **`SafeFetchTarget` only** (Orchestrator passes `$guardResult->safe`), not an arbitrary `string` and not `GuardResult`. Then an unvalidated URL cannot be fetched by accident through the type surface of the next stage.

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- [Validation & serialization (DTO vs domain)](https://stevenzg.com/software-development/back-end/validation-and-serialization)

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `UrlGuard` | Service | Normalize the string, enforce local policy, call the SSRF/DNS collaborator, build the result or throw |
| `SafeFetchTarget` | Value object | Immutable trusted coordinates for one fetch attempt |
| `GuardResult` | Value object | `SafeFetchTarget` plus the original input string for logging |
| `SsrfUrlValidator` | Interface (port) | “Given a URL string, return public IPs or fail” — production impl wraps craftcms |
| `UrlGuardException` + `UrlGuardError` | Error model | One exception type; an enum says *which kind* of failure |

**Why UrlGuard is not a DTO:** a DTO carries data across a boundary. UrlGuard **runs** logic and has a dependency (the validator). The DTO-like pieces are `SafeFetchTarget` and `GuardResult`.

**Why input stays `string`:** the wire value is one field. Wrapping it in a request DTO does not add safety. Optional opacity wrappers are out of scope for the MVP.

**Why `original` is separate from `SafeFetchTarget`:** logs and error messages may need what the user typed. That string must never be used as the fetch URL. Keeping it on `GuardResult` (not on the trusted target) reduces misuse.

---

## Optional collaborator, production default

In production you almost always want craftcms + the system DNS resolver. A different implementation is rare outside tests (custom DNS cache, offline stub).

Keep the **interface** (for fakes and vendor isolation). Do **not** require callers to pass it every time:

```text
public function __construct(?SsrfUrlValidator $validator = null)
{
    $this->validator = $validator ?? new CraftCmsSsrfUrlValidator();
}
```

| Call site | Usage |
|-----------|--------|
| CLI / normal library use | `new UrlGuard()` |
| Unit tests of UrlGuard | `new UrlGuard($fake)` — fixed IPs or thrown errors, no real DNS |
| Custom DNS resolution | `new UrlGuard(new CraftCmsSsrfUrlValidator($dnsFn))` when the adapter supports it |

craftcms `UrlValidator` can take a custom resolver closure; that is an official way to avoid real DNS in tests of the adapter itself.

---

## Error model: one exception, tagged kind

Failures abort before fetch (**fail closed**). The orchestrator catches a single type and branches on an enum—similar in spirit to a Rust error enum, while still using PHP exceptions.

```text
enum UrlGuardError
{
    case Syntax;
    case Policy;
    case Rejected;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Syntax => 'URL is missing, malformed, or has no host',
            self::Policy => 'URL scheme or credentials are not allowed',
            self::Rejected => 'URL was rejected by SSRF/DNS validation',
        };
    }
}

final class UrlGuardException extends \RuntimeException
{
    public function __construct(
        public readonly UrlGuardError $error,
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

Meaning of each case:

| Case | Plain meaning | Default message (via `defaultMessage()`) | Who detects it |
|------|---------------|------------------------------------------|----------------|
| `Syntax` | Cannot parse a usable URL / missing host | `URL is missing, malformed, or has no host` | UrlGuard (local, no network) |
| `Policy` | Parseable but disallowed by our rules (e.g. not `http`/`https`, has userinfo) | `URL scheme or credentials are not allowed` | UrlGuard (local) |
| `Rejected` | Hostname resolution failed, or resolved IPs are not allowed (SSRF policy), or the vendor rejected the URL for another safety reason | `URL was rejected by SSRF/DNS validation` | Collaborator; UrlGuard wraps with `$previous` |

Throw sites may pass a more specific `$message` (e.g. which scheme was seen). If `$message` is empty, the exception uses `$error->defaultMessage()` so the kind alone still yields a clear, stable string—not the bare enum name.

**Why one `Rejected` instead of separate `Dns` and `Ssrf`:** craftcms exposes a single `UrlValidationException` and does not reliably split “could not resolve” from “resolved to a blocked IP”. For this pipeline recovery is the same either way: **do not fetch**. Splitting the enum would invent a distinction the collaborator does not give us. Details stay in `$message` and `$previous` for logs.

A hierarchy of exception classes is unnecessary: recovery is the same (do not fetch); only logging / exit codes may differ by `$e->error`.

---

## Public API (implementation surface)

### `UrlGuard`

```text
final class UrlGuard
{
    private SsrfUrlValidator $validator;

    public function __construct(?SsrfUrlValidator $validator = null)
    {
        $this->validator = $validator ?? new CraftCmsSsrfUrlValidator();
    }

    /**
     * @throws UrlGuardException
     */
    public function guard(string $raw): GuardResult
}
```

### `SsrfUrlValidator`

```text
interface SsrfUrlValidator
{
    /**
     * Check SSRF policy and resolve public IPs for pinning.
     *
     * @return list<string> Public IP addresses (non-empty on success)
     * @throws \Throwable Collaborator failure; UrlGuard maps into UrlGuardException
     */
    public function validate(string $url): array;
}
```

Default: `CraftCmsSsrfUrlValidator` wraps craftcms `UrlValidator`.

### `SafeFetchTarget`

Immutable. PHP cannot enforce package-private construction, so its constructor is public by necessity. Production callers conventionally treat `UrlGuard` as the only constructor of trusted targets; tests may construct fixtures directly.

| Property | Type | Meaning |
|----------|------|---------|
| `requestUri` | `string` | Canonical URL for the HTTP client and downstream provenance — still uses the **hostname**, not a raw IP in the URL |
| `host` | `string` | Hostname for TLS SNI, `Host` header, and pin entries |
| `port` | `int` | Port from the URL, or `443` / `80` from the scheme |
| `ips` | `list<string>` | Public IPs already checked — used only for pinning |

```text
public function curlResolveEntries(): array
// → ["{host}:{port}:{ip}", …]  (one entry per IP)
```

**IPv6 in pin entries:** use bare address forms as returned by the validator (e.g. `2001:db8::1`), without surrounding brackets inside the `{ip}` slot. Return **one `CURLOPT_RESOLVE` entry per IP**. `host` in the entry must match the hostname in `requestUri` (ASCII LDH only in MVP).

`HttpFetcher` must use `requestUri` + `curlResolveEntries()`. It must not fetch `GuardResult::$original`. craftcms / `SsrfUrlValidator` returns **IPs only** on success — it does not return a normalized URL; UrlGuard owns the canonical `requestUri` string.

### `GuardResult`

```text
final class GuardResult
{
    public function __construct(
        public readonly SafeFetchTarget $safe,
        public readonly string $original,
    ) {}
}
```

`original` is for logs/errors. It is **not** safe for network I/O.

---

## Logical flow of `guard()`

```text
guard($raw)
  │
  ├─ Trim whitespace; parse_url; missing host / unparsable
  │     → UrlGuardError::Syntax
  │
  ├─ Scheme not http|https; userinfo present; other local policy
  │     → UrlGuardError::Policy
  │
  └─ $validator->validate($normalizedUri)     ← DNS + IP / SSRF policy (I/O)
        └─ any collaborator failure (no resolve, blocked IP, …)
              → UrlGuardError::Rejected  (+ $previous)


  success → GuardResult(
               SafeFetchTarget(requestUri, host, port, ips),
               original: $raw
             )
```

### Steps owned by UrlGuard (no DNS)

1. Trim leading/trailing whitespace before parse.
2. `parse_url` must succeed; `host` required and non-empty.
3. Allow only schemes `http` and `https`.
4. Reject URLs that include `user` / `pass`.
5. Reject **literal IP hosts** (IPv4 or IPv6 in brackets), including **public** literals such as `http://8.8.8.8/` or `http://[2001:db8::1]/` → `UrlGuardError::Policy`. MVP allows hostname-only targets; SSRF IP checks then apply only to DNS resolution results, not to “host is already an IP” URLs.
6. Reject **IDN / non-ASCII hosts** (e.g. `https://münchen.example/`) → `UrlGuardError::Policy`. MVP does not convert to punycode; hostname must be LDH ASCII (`a-z`, `0-9`, `-`, labels). Punycode / `ext-intl` support is deferred (Phase 7).
7. Derive `port`: from the URL, else `443` if `https`, else `80`.
8. Build **`requestUri`** as UrlGuard’s **canonical** string passed to the validator and later to the client (and copied as `sourceUrl` after fetch). MVP rules:
   - Lowercase the scheme.
   - Keep host, path, query, and **fragment** when present (fragment is part of our canonical form / provenance even though HTTP does not send it on the wire).
   - Default path to `/` when missing.
   - Include non-default ports in the string; omit `:80` / `:443` when they match the scheme default (document the chosen reconstruction in tests).
   - Do **not** use `GuardResult::$original` as the fetch URL.

### Steps owned by `SsrfUrlValidator` (DNS + IP policy)

1. Resolve the hostname to IP address(es).
2. Reject if any address is non-public (loopback, private LAN, link-local / metadata, related IPv6 cases — as implemented by craftcms).
3. On success, return the list of **public** IPs for pinning.

### How the next stage uses the result

```text
CURLOPT_RESOLVE => $safe->curlResolveEntries()
client->get($safe->requestUri, [ 'curl' => [ CURLOPT_RESOLVE => … ] ])
```

Hostname in the URL (SNI / `Host`) stays correct; TCP uses the pinned IPs.

---

## Testing guidance

### Approach

- Unit-test `UrlGuard` with a **fake** `SsrfUrlValidator` (return a fixed IP list, or throw). No real DNS; no reflection on private methods.
- Prefer PHPUnit data providers grouped by expected outcome (`Syntax`, `Policy`, success, `Rejected`).
- On success, assert `host`, `port`, `ips`, `requestUri`, `original`, and `curlResolveEntries()` shape.
- Optionally test `CraftCmsSsrfUrlValidator` separately with craftcms’s custom DNS resolver closure (real IP policy, still no system DNS).

Input families below map to error kinds / success. Example strings are starting points; add cases if implementation details of `parse_url` need extra coverage.

### Not a URL → `UrlGuardError::Syntax`

Fake must not be required (failure happens before the collaborator).

| Case | Example input |
|------|----------------|
| Empty string | `""` |
| Whitespace only | `"   \n"` |
| Free text | `"not a url"` |
| Scheme without host | `"http://"` / `"https://"` |
| Relative path | `"/article"` |
| Odd authority | `"http:///path"` |
| Scheme only | `"https:"` |

### Correct URL (http and https) → success

Fake returns fixed public IPs (e.g. `['203.0.113.10']` — TEST-NET, safe for docs/tests).

| Case | Example input | Notes |
|------|----------------|-------|
| HTTPS minimal | `https://example.com` | port `443` |
| HTTP minimal | `http://example.com` | port `80` |
| Path, query, fragment | `https://example.com/a/b?x=1#y` | `requestUri` keeps path, query, **and fragment** (canonical form; fragment not sent on GET) |
| Explicit port | `https://example.com:8443/` | port `8443` in pin entry |
| Leading/trailing spaces | `"  https://example.com/x  "` | trim for parse; `original` keeps raw input |
| Subdomain | `https://www.example.com` | |
| Multiple IPs from validator | fake → `['203.0.113.10','203.0.113.11']` | `curlResolveEntries()` lists all |

### Unsupported URL → `UrlGuardError::Policy`

Local policy; collaborator should not be called.

| Case | Example input |
|------|----------------|
| `file:` | `file:///etc/passwd` |
| `ftp:` | `ftp://ftp.example.com/file` |
| `data:` | `data:text/html,hi` |
| Other non-http(s) schemes | `gopher://…`, `javascript:…` |
| User + password | `https://user:pass@example.com/` |
| User only | `https://user@example.com/` |
| Literal IPv4 (any) | `http://127.0.0.1/`, `http://8.8.8.8/`, `http://10.0.0.1/` |
| Literal IPv6 (any) | `http://[::1]/`, `http://[2001:db8::1]/` |
| IDN / non-ASCII host | `https://münchen.example/` |

### Malicious / SSRF-oriented URL → `UrlGuardError::Rejected`

Fake **throws** (UrlGuard maps to `Rejected` + `$previous`). The input string can still look “URL-shaped”; the collaborator refusal is what under test. Literal IP URLs never reach this path in MVP (they fail `Policy` above).

| Case | Example input | What the fake simulates |
|------|----------------|-------------------------|
| Benign hostname, private resolve | `https://internal.example/` | DNS would return `10.x` → throw |
| Benign hostname, loopback resolve | `https://evil.example/` | DNS would return `127.0.0.1` → throw |
| Benign hostname, metadata resolve | `https://meta.example/` | DNS would return `169.254.169.254` → throw |
| DNS failure | `https://no-such-host.invalid/` | resolution failure → same `Rejected` |

In UrlGuard unit tests, the fake does not need to implement real IP math: any throw becomes `Rejected`. Cover real range checks in **adapter** tests with a stub DNS resolver that returns `127.0.0.1` vs `203.0.113.10`.

### Optional extras

| Case | Example | Expected |
|------|---------|----------|
| Uppercase scheme | `HTTPS://example.com` | success after normalization (lowercase scheme) |
| IDN host | `https://münchen.example/` | `Policy` (MVP — no punycode; Phase 7) |

---

## Out of scope for UrlGuard

These belong to later pipeline stages or the orchestrator:

- Performing the HTTP GET, timeouts, body size limits, Content-Type checks (`HttpFetcher`).
- Following redirects: **MVP HttpFetcher does not follow redirects** (see [HttpFetcher.md](HttpFetcher.md)). If redirect following is added later, each hop’s `Location` must be passed through `guard()` again and re-pinned before the next request.
- Character encoding (`EncodingNormalizer`), Readability extraction, HTMLPurifier.
