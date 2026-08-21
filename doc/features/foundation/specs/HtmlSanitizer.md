# HtmlSanitizer

This document is both a **design spec** (what to implement) and a **walkthrough** of why HtmlSanitizer exists and how data moves through it. HTML purification and the project URI-scheme policy are introduced before they are used in APIs.

## Where HtmlSanitizer sits

The larger pipeline turns a remote page into cleaned article HTML:

```text
input URL string
  → UrlGuard
  → HttpFetcher
  → EncodingNormalizer
  → ArticleExtractor
  → HtmlSanitizer   (this document)
  → article HTML
```

HtmlSanitizer is the **fifth** stage. It receives a **`ReadableDocument`** (article HTML already extracted). It does **not** re-run Readability and does **not** accept bare `string` / `Utf8Html`.

```text
ReadableDocument → HtmlSanitizer → SafeDocument
```

Purifier almost always **returns** a cleaned string (dangerous markup is stripped, not thrown). Hard failures are rare (misconfiguration / runtime inability to run Purifier) and use `HtmlSanitizerException`.

---

## Problem: extracted HTML is not safe to render

Readability optimizes for *readable* main content. It is **not** an XSS allowlist. The winning node may still contain odd tags, event-handler attributes Readability missed, or URLs with dangerous schemes if they survived earlier stages.

| Input situation | Why it is a problem |
|-----------------|---------------------|
| `<script>` / `<iframe>` in article subtree | Stored XSS if displayed as HTML |
| `href="javascript:…"` / exotic schemes | Script execution via links |
| Wide transitional tag set from defaults | Surprising markup in storage/UI |
| Trusting “Readability already cleaned it” | Defense-in-depth failure |

HtmlSanitizer’s job: **parse** `ReadableDocument` → `SafeDocument` under a documented Purifier policy. After a returned `SafeDocument`, callers treat `$html` as a **UTF-8 fragment** suitable to store or display as HTML. Metadata fields remain `PlainText` (see ArticleExtractor spec): store with `raw()`, embed in HTML with `html()`.

---

## Design principle: parse, don’t validate

**Validate** = “is this safe?” → bool, discard the cleaned output.

**Parse** = produce `SafeDocument`, whose invariant is “`$html` was purified under our policy (UTF-8, tight URI schemes, untrusted HTML mode)”.

Illegal states:

- Calling Purifier on full-page `Utf8Html` (skipping extraction) as the normal product path.
- Passing `ReadableDocument::$title` through Purifier and calling that “safe document”.
- Exposing raw `HTMLPurifier_Config` as the public policy type.

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- `doc/features/foundation/context/Foundational Research.md` §6
- HTMLPurifier docs / config schema (`Core.Encoding`, `URI.AllowedSchemes`, `Core.CollectErrors`, `Cache.DefinitionImpl`)

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `HtmlSanitizer` | Service | Build Purifier config from policy, purify article content, return `SafeDocument` |
| `PurifyPolicy` | Value object | Immutable knobs (schemes, debug); encoding/doctype fixed internally |
| `SafeDocument` | Value object | Purified UTF-8 HTML fragment + `PlainText` metadata + `sourceUrl` |
| `PlainText` | Value object | Defined in ArticleExtractor spec; copied through unchanged |
| `HtmlSanitizerException` + `HtmlSanitizerError` | Error model | Rare hard failures |

**Why HtmlSanitizer is not a DTO:** it runs purification logic and owns vendor config mapping. The DTO-like pieces are `PurifyPolicy` and `SafeDocument`.

**Why not model every stripped tag as `Degraded`:** stripping is the **normal** success path. Unlike encoding lossiness, it is not a quality warning for the orchestrator by default. Optional empty-fragment detection may be logged; it is not an `EncodingOutcome`-style soft enum for MVP.

**Why encoding is not on `PurifyPolicy`:** the whole pipeline already guarantees UTF-8 via `Utf8Html` / `ReadableDocument`. `Core.Encoding = UTF-8` is hard-coded. Exposing other encodings would reopen the EncodingNormalizer problem.

---

## Baseline Purifier behaviour (vendor defaults)

With `HTMLPurifier_Config::createDefault()` and `HTML.Trusted = false`:

- Dangerous elements (`script`, `iframe`, `object`, `form`, …) are **not** in the effective allow set.
- URI validation is active on URL-bearing attributes.
- Default allowed schemes include `http`, `https`, `mailto`, `ftp`, `nntp`, `news`, `tel`.
- `data:` and `file:` are **not** enabled by default.

**This project tightens URI schemes** to what articles need:

```text
http, https, mailto
```

**Doctype:** use a value HTMLPurifier supports (e.g. `XHTML 1.0 Transitional`). **`html5` is not a valid `HTML.Doctype`** in HTMLPurifier. Fix the doctype in the adapter; do not expose it on MVP policy.

**Optional later (`HTML.Allowed`):** tighter article tag allowlist (headings, paragraphs, lists, links, images, emphasis, optional tables). Out of scope for MVP; Phase 7 / hardening in `feature.md`.

---

## Public API (implementation surface)

### `PurifyPolicy`

Immutable. Self-validating construction (non-empty scheme list, known scheme names, etc.).

| Property | Type | Default | Meaning |
|----------|------|---------|---------|
| `allowedSchemes` | `list<string>` or lookup map | `http`, `https`, `mailto` | Mapped to `URI.AllowedSchemes` |
| `debug` | `bool` | `false` | Debug-only Purifier behaviour (see below) |

```text
final readonly class PurifyPolicy
{
    /**
     * @param list<string> $allowedSchemes
     */
    public function __construct(
        public array $allowedSchemes = ['http', 'https', 'mailto'],
        public bool $debug = false,
    ) {
        // assert non-empty, lowercase scheme tokens, no javascript/data/file unless explicitly added later
    }
}
```

**Fixed inside the adapter (not on policy):**

| Config key | Value |
|------------|--------|
| `Core.Encoding` | `UTF-8` |
| `HTML.Doctype` | `XHTML 1.0 Transitional` (or another supported doctype chosen once in code) |
| `HTML.Trusted` | `false` (default) |

### Debug mapping (`PurifyPolicy::$debug`)

Debug must **not** change the safety invariant of `SafeDocument` (same allow rules). It only changes cost / observability:

| `debug === false` (default) | `debug === true` |
|-----------------------------|------------------|
| Real definition cache enabled (writable project cache path) | `Cache.DefinitionImpl = null` (rebuild every run; spike behaviour) |
| `Core.CollectErrors = false` | Optional `Core.CollectErrors = true`; log collector output internally only |

**Warning:** `Core.CollectErrors` is marked experimental / patchy upstream. Use for developer diagnostics, **not** as a product failure contract.

Spike `bin/run` sets `Cache.DefinitionImpl = null` unconditionally — production must **not**.

### `HtmlSanitizer`

```text
final class HtmlSanitizer
{
    public function __construct(
        ?PurifyPolicy $policy = null,
        // optional: cache path / filesystem collaborator for definition cache
    ) { … }

    /**
     * @throws HtmlSanitizerException
     */
    public function purify(ReadableDocument $document): SafeDocument
}
```

Only `$document->content` is passed to `HTMLPurifier::purify()`. Title / excerpt / siteName are **`PlainText` instances copied by reference/value** — not re-parsed, not purified as HTML, not re-escaped here.

### `SafeDocument`

Immutable. Constructible only from `HtmlSanitizer`.

| Property | Type | Meaning |
|----------|------|---------|
| `html` | `string` | Purified UTF-8 HTML fragment (safe to emit as HTML; do not `htmlspecialchars` the whole fragment) |
| `title` | `PlainText` | Copied from `ReadableDocument` |
| `excerpt` | `?PlainText` | Copied |
| `siteName` | `?PlainText` | Copied |
| `sourceUrl` | `string` | Propagated provenance |

```text
final readonly class SafeDocument
{
    public function __construct(
        public string $html,
        public PlainText $title,
        public ?PlainText $excerpt,
        public ?PlainText $siteName,
        public string $sourceUrl,
    ) {}
}
```

**Invariants on success:**

- `html` is UTF-8 and purified under the policy.
- URI schemes in URL-bearing attributes respect `allowedSchemes` (enforced by Purifier).
- `sourceUrl` equals the input document’s `sourceUrl`.
- Metadata `PlainText` values are identical to the input document’s (same `raw()`).

**Storage vs display (reminder):**

| Field | DB / FS / JSON | HTML page |
|-------|----------------|-----------|
| `html` | store as-is (UTF-8) | emit as HTML fragment |
| `title` / `excerpt` / `siteName` | `$field->raw()` | `$field->html()` |

**Empty fragment:** if Purifier returns empty/whitespace-only HTML, still return `SafeDocument` for MVP (stripping can legitimately remove everything). Orchestrator may treat empty body as a product-level soft failure if desired; that is **not** `HtmlSanitizerException`.

---

## Error model: rare hard failures

HTMLPurifier’s `purify()` returns a `string` for ordinary dirty HTML. It does **not** throw because a `<script>` was present.

```text
enum HtmlSanitizerError
{
    case Configuration;
    case Unexpected;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Configuration => 'HTML sanitizer configuration or runtime setup failed',
            self::Unexpected => 'HTML sanitization failed unexpectedly',
        };
    }
}

final class HtmlSanitizerException extends \RuntimeException
{
    public function __construct(
        public readonly HtmlSanitizerError $error,
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

| Case | Typical cause |
|------|----------------|
| `Configuration` | Invalid policy mapping, cache path unusable when cache is required, Purifier encoder/config exceptions |
| `Unexpected` | Other throwables from the adapter |

Wrap vendor `\Exception` / `\Error` at the boundary; orchestrator depends only on `HtmlSanitizerException`.

---

## Logical flow of `purify()`

```text
purify($document)
  │
  ├─ Build HTMLPurifier_Config::createDefault()
  │     Core.Encoding = UTF-8
  │     HTML.Doctype = fixed supported value
  │     URI.AllowedSchemes = policy.allowedSchemes
  │     if policy.debug: Cache.DefinitionImpl = null; optional CollectErrors
  │     else: enable definition cache on configured path
  │
  ├─ try $pure = $purifier->purify($document->content)
  │     └─ catch → HtmlSanitizerException(Configuration|Unexpected)
  │
  └─ return SafeDocument{
        html: $pure,
        title / excerpt / siteName / sourceUrl from $document
      }
```

---

## Testing guidance

### Approach

- No network: build `ReadableDocument` fixtures with crafted `content` + `sourceUrl`.
- Assert dangerous constructs are removed or neutralized; benign markup retained.
- Assert `javascript:` (and other non-allowed schemes) do not survive in `href` / `src`.
- Unit-test `PurifyPolicy` rejects empty / nonsense scheme lists if validation is specified.
- Optionally assert debug vs non-debug does not change stripping of a fixed XSS fixture (safety invariant).

### Suggested cases

| Case | Input sketch | Expected |
|------|--------------|----------|
| Script tag | `<p>x</p><script>alert(1)</script>` | no `script` in `html` |
| javascript: URL | `<a href="javascript:alert(1)">` | href removed or emptied / non-executable |
| Allowed link | `<a href="https://example.com/a">` | kept |
| mailto | `<a href="mailto:a@b.c">` | kept |
| ftp (not in policy) | `<a href="ftp://example.com/f">` | stripped or non-ftp |
| Image https | `<img src="https://…/x.png" alt="x">` | kept under default element set |
| Provenance | any | `sourceUrl` / `title->raw()` unchanged on `SafeDocument` |
| Metadata type | title on input is `PlainText` | same instance/`raw()` on output; not passed through Purifier |

---

## Out of scope for HtmlSanitizer

- Main-content extraction (`ArticleExtractor`).
- Charset cascade (`EncodingNormalizer`).
- Strict `HTML.Allowed` article allowlist (Phase 7).
- Re-implementing `PlainText` escaping (callers use `$title->html()`; see ArticleExtractor).
- Making Purifier’s error collector a product-level `Degraded` outcome.
- Accepting raw `string` HTML on the public `purify()` API.
