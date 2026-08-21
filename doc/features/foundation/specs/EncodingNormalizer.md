# EncodingNormalizer

This document is both a **design spec** (what to implement) and a **walkthrough** of why EncodingNormalizer exists and how data moves through it. Character encoding, BOM, and the detection cascade are introduced before they are used in APIs.

## Where EncodingNormalizer sits

The larger pipeline turns a remote page into cleaned article HTML (`doc/notes.md`):

```text
input URL string
  → UrlGuard
  → HttpFetcher
  → EncodingNormalizer   (this document)
  → ArticleExtractor
  → HtmlSanitizer
  → article HTML
```

EncodingNormalizer is the **third** stage. HttpFetcher deliberately does **not** convert charsets (no Guzzle transcoder middleware). This stage turns opaque response bytes into a UTF-8 HTML string the rest of the stack can assume.

```text
FetchedPage → EncodingNormalizer → EncodingOutcome { quality, Utf8Html, … }
                                    ├─ Ok        → continue (optional: log nothing)
                                    └─ Degraded  → continue with warning (orchestrator chooses UX)
```

`ArticleExtractor` still receives **`Utf8Html` only** (`$outcome->html`). The outcome’s `quality` is for the orchestrator (log, CLI warning, metrics)—not for re-deciding charset downstream.

`Utf8Html` also carries **`sourceUrl`**, copied from `FetchedPage::$requestUri`, so later stages (relative URL fixing in Readability, logging, `ReadableDocument` / `SafeDocument`) always have the fetch URL beside the UTF-8 HTML. EncodingNormalizer does **not** invent or re-validate the URL; it only propagates it.

---

## Problem: bytes are not yet text for our stack

PHP’s `string` is a byte buffer. After fetch we know:

- the payload is bounded and Content-Type looked like HTML;
- we do **not** know that those bytes are valid UTF-8, nor which encoding the author intended.

Downstream tools in this project expect UTF-8:

| Consumer | Why UTF-8 matters |
|----------|-------------------|
| Readability | Parses HTML as Unicode text |
| HTMLPurifier | `Core.Encoding = UTF-8` |
| Storage / CLI output | One internal representation |

Examples of bad or ambiguous input:

| Input situation | Why it is a problem |
|-----------------|---------------------|
| `Content-Type: text/html; charset=ISO-8859-1` with latin1 bytes | Treating as UTF-8 → mojibake |
| Header says UTF-8, meta says windows-1252 | Conflicting declarations |
| No charset anywhere | Must fall back carefully |
| Leading UTF-8 BOM `EF BB BF` | Some parsers dislike BOM; still a strong signal |
| Binary / invalid sequences labelled as UTF-8 | Mojibake or parser noise if forced |

EncodingNormalizer’s job: **parse** `FetchedPage` → `EncodingOutcome` carrying a `Utf8Html`. After a returned outcome, callers must not re-detect encoding for the article pipeline; they only choose how to treat `Ok` vs `Degraded`.

---

## Design principle: parse, don’t validate

**Validate** = “is this UTF-8?” → bool, discard how you know.

**Parse** = produce `Utf8Html`, a type whose invariant is “`$html` is UTF-8 suitable for this stack” (syntactically valid UTF-8; may be lossy if quality is `Degraded`) **and** `$sourceUrl` is the fetch URI this HTML came from (`FetchedPage::$requestUri`).

Illegal state: handing Readability a bare `FetchedPage::$body` or a successful path that is not UTF-8. Do **not** model the extractor input as `{ data, encoding: 'utf-8' | 'other' | 'unknown' }`. Do **not** drop `requestUri` and pass a second loose `baseUrl` string into the extractor.

Quality of the parse is explicit on **`EncodingOutcome`**, not by weakening `Utf8Html`:

| Quality | Meaning |
|---------|---------|
| `Ok` | Cascade found a trusted encoding and conversion succeeded cleanly |
| `Degraded` | Still `Utf8Html`, but via lossy / best-effort path; carry `EncodingError` as warning |

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- `doc/01 - research.md` §4
- HTML5 encoding sniffing model (HTTP charset vs meta): [WHATWG encoding / HTML](https://html.spec.whatwg.org/multipage/parsing.html#determining-the-character-encoding) (conceptual priority; we implement a practical subset)

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `EncodingNormalizer` | Service | Run detection cascade, convert to UTF-8, strip BOM, return `EncodingOutcome` (or throw if even lossy UTF-8 is impossible) |
| `EncodingOutcome` | Value object | Discriminated result: always includes `Utf8Html`; `quality` says Ok vs Degraded |
| `EncodingQuality` | Enum | `Ok` \| `Degraded` — how the orchestrator should treat the result |
| `Utf8Html` | Value object | Immutable UTF-8 HTML string + `sourceUrl` provenance (+ optional encoding diagnostics) |
| `EncodingError` | Enum | Kind of encoding problem (used as **warning** on Degraded, or on the rare hard failure exception) |
| `EncodingNormalizerException` | Exception | Hard failure only when no `Utf8Html` can be produced |
| Charset helpers (optional ports) | Detection / conversion | Thin wrappers around vendor or `mb_*` / iconv so tests can stub conversion |

**Why `EncodingOutcome` instead of throwing on every soft failure:** the orchestrator can always continue to Readability with `$outcome->html` and decide whether to warn, metric, or abort on `Degraded`. That is more explicit than try/catch-only recovery.

**Why not a Rust-style enum with payloads in PHP:** PHP enums do not carry associated data. Model the sum type as a **readonly VO + `EncodingQuality` discriminant** with private construction and `ok()` / `degraded()` factories (invariants enforced there).

**Why this is a separate stage from HttpFetcher:** single responsibility — transport limits vs text encoding. Content-Type is used again here as a **charset hint**, not only as an HTML gate.

**Why not Guzzle middleware:** middleware hides the guarantee inside the HTTP client, couples tests to HandlerStack, and mixes stages. We keep fetch → bytes, then normalize → `EncodingOutcome` (Approach 3).

---

## Do not confuse headers

| Header / concept | Meaning |
|------------------|---------|
| `Content-Encoding` | Compression (`gzip`, `br`) — handled by the HTTP client, not this stage |
| `Content-Type` MIME | e.g. `text/html` — already gated by HttpFetcher |
| `Content-Type; charset=` | Declared text encoding of the body — **input to this stage** |

---

## Detection cascade (owned by EncodingNormalizer)

Priority (browser-like / project policy from `doc/01 - research.md` §4.2):

```text
1. BOM (byte order mark) on raw body
2. charset from HTTP Content-Type  (FetchedPage::$contentType)
3. charset from HTML <meta> / http-equiv in a short byte prescan
4. If still unknown: treat as UTF-8 only if mb_check_encoding succeeds
5. Last resort: mb_detect_encoding (heuristic) — optional; weak → prefer Degraded
6. Convert declared/detected encoding → UTF-8
7. Strip UTF-8 BOM if present on the result; verify UTF-8 validity
```

### Step details

**1. BOM**

| BOM bytes | Interpretation |
|-----------|----------------|
| `EF BB BF` | UTF-8 — prefer as source encoding; strip before returning `Utf8Html` |
| Other BOMs (UTF-16/32) | Out of scope for MVP article HTML, or map to Degraded / hard failure |

Check **raw bytes**, not `mb_detect_encoding`.

**2. HTTP charset**

Parse `charset` from `FetchedPage::$contentType` (case-insensitive parameter).

In the **HTML5 model**, a charset from the HTTP header wins over a conflicting meta declaration when both exist. **This project follows that policy** for steps 2 vs 3.

**3. Meta / http-equiv prescan**

Scan roughly the **first 1024 bytes** with regex (no DOM, no Purifier — chicken-and-egg). Support:

- HTML5 `<meta charset="…">`
- HTML4 `<meta http-equiv="content-type" content="text/html; charset=…">`

Reuse vendor parsing where useful: `Fossar\GuzzleTranscoder\ContentTypeExtractor` already implements header and HTML meta extraction (same package as `fossar/guzzle-transcoder`, already in Composer). Prefer calling that **as a library**, not as Guzzle middleware.

**4–5. Validity / guess**

- If no declaration: `mb_check_encoding($body, 'UTF-8')` → assume UTF-8 → typically `EncodingQuality::Ok`.
- Else optional `mb_detect_encoding` among a small candidate list — **never** as the only strategy when a declaration exists; treat detect-only as **`Degraded`** (warning `Undeclared` or a dedicated note in `$message`).
- If still unknown → attempt **lossy** path (see below) → `Degraded` + `EncodingError::Undeclared`, not an immediate throw.

**6. Convert**

Use a transcoder that can fall back across `mbstring` / `iconv`:

- Preferred: `Ddeboer\Transcoder\Transcoder` (dependency of `fossar/guzzle-transcoder`; see package README).
- Or `mb_convert_encoding` / `iconv` with clear error handling.

If source encoding equals UTF-8, still run validity check. Invalid sequences: prefer a lossy repair (replacement characters / ignore) → `Degraded` + `EncodingError::Conversion`, rather than aborting the pipeline by default.

**7. Post-condition (both Ok and Degraded)**

- Result string must pass `mb_check_encoding(..., 'UTF-8')`.
- Strip leading UTF-8 BOM from the returned HTML.
- Optionally rewrite meta/header declarations in the body to `utf-8` (nice for consistency; not required for MVP). Default in fossar middleware is **not** to rewrite body meta (`replaceContent: false`); matching that is fine.

### Lossy / best-effort path (feeds `Degraded`)

When strict conversion fails or encoding is undeclared:

1. Try to produce valid UTF-8 anyway (e.g. assume UTF-8 with substitution, or `iconv`/`mb` with `//IGNORE` / replacement).
2. If that yields valid UTF-8 → `EncodingOutcome::degraded($html, $warning, $previous)`.
3. If even that fails → `EncodingNormalizerException` (hard fail; no lie about `Utf8Html`).

Mojibake risk is accepted on `Degraded`; security impact is low relative to SSRF/XSS stages (Purifier still runs). Quality may be poor—that is why the warning exists.

### Important: do not call `GuzzleTranscoder` blindly as the cascade owner

`Fossar\GuzzleTranscoder\GuzzleTranscoder::convertResponse()` is useful as a **reference implementation**, but:

- It requires a parseable Content-Type header or returns `null`.
- Its effective preference when both header and body declare a charset is **body over header** in the current package code — which **disagrees** with our HTTP-wins policy above.

**MVP recommendation:**

1. Detect with our cascade (BOM → HTTP → meta → …), optionally using `ContentTypeExtractor` for header/meta parsing only.
2. Convert with `Ddeboer\Transcoder\Transcoder` (or `mb_*`).
3. Do **not** push `GuzzleTranscoder` on the Guzzle HandlerStack.

If wrapping `convertResponse` later for convenience, override or pre-resolve encoding so HTTP charset wins when present.

---

## Result model: `EncodingQuality` + `EncodingOutcome`

PHP has no enum-with-payloads like Rust’s `Ok(T) | Degraded(T, E)`. Use a single immutable outcome and a quality tag.

```text
enum EncodingQuality
{
    case Ok;
    case Degraded;
}

final readonly class EncodingOutcome
{
    private function __construct(
        public EncodingQuality $quality,
        public Utf8Html $html,
        public ?EncodingError $warning = null,
        public ?\Throwable $previous = null,
    ) {
        if ($quality === EncodingQuality::Ok && $warning !== null) {
            throw new \InvalidArgumentException('Ok outcome must not carry a warning');
        }
        if ($quality === EncodingQuality::Degraded && $warning === null) {
            throw new \InvalidArgumentException('Degraded outcome requires a warning');
        }
    }

    public static function ok(Utf8Html $html): self
    {
        return new self(EncodingQuality::Ok, $html);
    }

    public static function degraded(
        Utf8Html $html,
        EncodingError $warning,
        ?\Throwable $previous = null,
    ): self {
        return new self(EncodingQuality::Degraded, $html, $warning, $previous);
    }

    public function isOk(): bool
    {
        return $this->quality === EncodingQuality::Ok;
    }

    public function isDegraded(): bool
    {
        return $this->quality === EncodingQuality::Degraded;
    }
}
```

| Field | On `Ok` | On `Degraded` |
|-------|---------|----------------|
| `html` | `Utf8Html` (clean convert) | `Utf8Html` (lossy / best-effort) |
| `warning` | `null` | `EncodingError` (why it is degraded) |
| `previous` | `null` | optional underlying converter exception |

**Orchestrator sketch:**

```text
$outcome = $normalizer->normalize($page);

match ($outcome->quality) {
    EncodingQuality::Ok => null, // or debug log
    EncodingQuality::Degraded => warn/log($outcome->warning, $outcome->previous),
};

$article = $extractor->extract($outcome->html); // always Utf8Html
```

The orchestrator may instead **abort** on `Degraded` (treat as failure): that is a product choice; the type still makes the branch explicit.

---

## Error model: warnings vs hard failure

`EncodingError` names the *kind* of encoding problem. It appears:

1. As **`$outcome->warning`** when quality is `Degraded` (pipeline may continue).
2. On **`EncodingNormalizerException`** only when no `Utf8Html` can be built.

```text
enum EncodingError
{
    case Undeclared;
    case Unsupported;
    case Conversion;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Undeclared => 'Character encoding could not be determined',
            self::Unsupported => 'Character encoding is not supported for conversion',
            self::Conversion => 'Character encoding conversion to UTF-8 failed',
        };
    }
}

final class EncodingNormalizerException extends \RuntimeException
{
    public function __construct(
        public readonly EncodingError $error,
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

| Case | Plain meaning | Typical quality |
|------|---------------|-----------------|
| `Undeclared` | No BOM, no HTTP charset, no meta, and UTF-8 validity / guess failed or was weak | `Degraded` after lossy assume/repair |
| `Unsupported` | Declared encoding name unknown; lossy fallback used or hard-failed | `Degraded` or exception |
| `Conversion` | Strict convert failed or invalid sequences; repaired or hard-failed | `Degraded` or exception |

A separate exception type from `HttpFetcherException` keeps stages independent (same rationale as UrlGuard vs fetch). Soft encoding problems should prefer **`EncodingOutcome::degraded`**, not throw, so the orchestrator retains control.

---

## Public API (implementation surface)

### `EncodingNormalizer`

```text
final class EncodingNormalizer
{
    public function __construct(
        // optional: TranscoderInterface $transcoder = null
    ) { … }

    /**
     * @throws EncodingNormalizerException when even lossy UTF-8 cannot be produced
     */
    public function normalize(FetchedPage $page): EncodingOutcome
}
```

Production default constructs the transcoder internally. Tests may inject a fake transcoder if the collaborator is extracted behind an interface.

### `Utf8Html`

Immutable. Invariants:

- `$html` is UTF-8 (no leading BOM). Same for both `Ok` and `Degraded` (Degraded may contain replacement characters / mojibake, but still valid UTF-8 bytes).
- `$sourceUrl` is the non-empty fetch URI copied from `FetchedPage::$requestUri` (not `GuardResult::$original`).

| Property | Type | Meaning |
|----------|------|---------|
| `html` | `string` | UTF-8 HTML suitable for Readability |
| `sourceUrl` | `string` | Page URL this HTML was fetched from; base for relative URL fixing downstream |
| `sourceEncoding` | `string` | Encoding used or assumed before conversion (diagnostic) |
| `source` | optional enum | Where the encoding came from: `Bom`, `HttpHeader`, `Meta`, `Utf8Default`, `Detect`, `Lossy` (diagnostic) |

```text
final readonly class Utf8Html
{
    public function __construct(
        public string $html,
        public string $sourceUrl,
        public string $sourceEncoding,
        // optional: public EncodingSource $source,
    ) {}
}
```

Constructible only from `EncodingNormalizer` (or package-private factory). Lossy construction stays inside the normalizer so callers never invent a fake `Utf8Html` from raw `FetchedPage` bytes. Always set `sourceUrl` from `$page->requestUri` on both `Ok` and `Degraded` paths.

**Why `sourceUrl` lives on `Utf8Html`:** the article pipeline is always “HTML of a remote URL”. Carrying the URI on the same VO avoids a parallel argument on `ArticleExtractor::extract()` and keeps provenance available for Readability `originalURL`, logging, and later `ReadableDocument` / `SafeDocument`.

**Why not put free-form encoding on `FetchedPage` for Readability:** that would leave “maybe not UTF-8” at the extractor boundary. `Utf8Html` is the proof; `EncodingQuality` is the honesty about how hard it was to get there.

---

## Logical flow of `normalize()`

```text
normalize($page)
  │
  ├─ $bytes ← $page->body
  ├─ $sourceUrl ← $page->requestUri   (propagate; do not re-parse as a security check)
  │
  ├─ If UTF-8 BOM → sourceEncoding = UTF-8, note source = Bom
  │
  ├─ Else parse charset from $page->contentType
  │     → if present, sourceEncoding = that, source = HttpHeader
  │
  ├─ Else prescan meta in first ~1024 bytes
  │     → if present, sourceEncoding = that, source = Meta
  │
  ├─ Else if mb_check_encoding($bytes, 'UTF-8')
  │     → sourceEncoding = UTF-8, source = Utf8Default
  │
  ├─ Else optional mb_detect_encoding …
  │     → weak/unknown → plan lossy (warning Undeclared)
  │
  ├─ Try strict transcode → UTF-8 + post-condition
  │     └─ success → EncodingOutcome::ok(Utf8Html(html, sourceUrl, …))
  │
  ├─ Else try lossy repair → UTF-8 + post-condition
  │     └─ success → EncodingOutcome::degraded(Utf8Html(html, sourceUrl, …), warning, previous)
  │
  └─ Else → throw EncodingNormalizerException (Unsupported / Conversion / Undeclared)
```

When **both** HTTP charset and meta are present, **HTTP wins** (skip meta for choosing `sourceEncoding`, though meta may still be rewritten later if we enable body rewrite).

---

## Testing guidance

### Approach

- No HTTP: build `FetchedPage` fixtures with crafted `body` + `contentType`.
- Prefer data providers by expected `EncodingQuality` (`Ok` / `Degraded`) and, when degraded, by `EncodingError` warning.
- Assert `$outcome->html` is always valid UTF-8 when an outcome is returned.
- Assert `$outcome->html->sourceUrl === $page->requestUri` on both Ok and Degraded.
- Cover cascade precedence explicitly (HTTP vs meta conflict).
- Unit-test BOM strip; cover one hard-failure path that throws (lossy also impossible).

### Suggested cases

| Case | Input sketch | Expected |
|------|--------------|----------|
| Header UTF-8, ASCII body | `charset=utf-8`, `<html>…` | `Ok`, source HTTP |
| Header ISO-8859-1, latin1 bytes | real 0xE9 etc. | `Ok`, UTF-8 é correct |
| Meta only charset | no charset in header, `<meta charset="windows-1252">` | `Ok` via meta |
| HTTP vs meta conflict | header UTF-8, meta ISO-8859-1 | `Ok`, HTTP wins |
| UTF-8 BOM | body starts with BOM | `Ok`; BOM stripped |
| No declaration, valid UTF-8 | empty charset, UTF-8 bytes | `Ok` via default |
| No declaration, invalid UTF-8 | random binary | `Degraded` + `Undeclared` (or throw if irreparable) |
| Unknown charset name | `charset=x-unknown` | `Degraded` + `Unsupported` (or throw if irreparable) |
| Invalid sequences under declared UTF-8 | | `Degraded` + `Conversion` after repair |

---

## Out of scope for EncodingNormalizer

- HTTP GET, pinning, redirects, size limits (`HttpFetcher`).
- Choosing whether Content-Type is HTML (already gated).
- Readability / HTMLPurifier (they consume `Utf8Html` / `ReadableDocument` from later stages).
- Preserving original bytes for forensics (pipeline working copy is UTF-8; raw body stays on `FetchedPage` if needed).
- Full WHATWG encoding sniffing algorithm (we implement a practical subset: BOM, header, meta, UTF-8 check, optional detect).
- A second public API that returns bare `Utf8Html` without quality (orchestrator should see `EncodingOutcome`; unwrap `->html` at the boundary to the extractor).
- SSRF / URL policy checks on `sourceUrl` (already done by UrlGuard; this stage only copies `requestUri`).
