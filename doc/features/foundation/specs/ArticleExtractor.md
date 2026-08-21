# ArticleExtractor

This document is both a **design spec** (what to implement) and a **walkthrough** of why ArticleExtractor exists and how data moves through it. Main-content extraction and the soft “no article” path are introduced before they are used in APIs.

## Where ArticleExtractor sits

The larger pipeline turns a remote page into cleaned article HTML:

```text
input URL string
  → UrlGuard
  → HttpFetcher
  → EncodingNormalizer
  → ArticleExtractor   (this document)
  → HtmlSanitizer
  → article HTML
```

ArticleExtractor is the **fourth** stage. It receives **`Utf8Html` only** (from `EncodingOutcome::$html`). It does **not** sanitize for XSS — that is `HtmlSanitizer`.

```text
Utf8Html → ArticleExtractor → ExtractResult
                                ├─ Ok(ReadableDocument)     → HtmlSanitizer (via Orchestrator)
                                └─ NoContent(…)             → typed soft result (no exception)
```

Hard failures (empty input, document too large) throw `ArticleExtractorException`. Soft “page has no article body” is **`ExtractResult::NoContent`**, not an exception. Product mapping (`NoContent` → `PipelineNoContent`) is owned by **Orchestrator**, not this stage.

---

## Problem: full page ≠ article

After encoding we have a UTF-8 HTML **document** (chrome, nav, ads, footer, plus the story). Downstream storage/display wants the **main content**, plus a few metadata fields.

| Input situation | Why it is a problem |
|-----------------|---------------------|
| Full news page HTML | Nav/ads drown the body if stored as-is |
| Page with no readable article | Must not invent `ReadableDocument` with empty content |
| Relative `href` / `src` | Broken links unless rewritten against the fetch URL |
| Treating extraction as XSS-safe | Readability is **not** a sanitizer |

ArticleExtractor’s job: **parse** `Utf8Html` → `ExtractResult` carrying either a `ReadableDocument` (article proof) or a typed no-content outcome. Vendor `fivefilters\Readability\Article` stays **inside** the adapter; it is not part of the public pipeline API.

---

## Design principle: parse, don’t validate

**Validate** = “is there content?” → bool, discard metadata / evidence.

**Parse** = produce either:

- `ReadableDocument` — invariant: non-null article `content` HTML was found; or
- `ExtractResult::NoContent` — metadata may still exist; there is **no** `ReadableDocument`.

Illegal states:

- Passing bare `string` / `FetchedPage::$body` into the extractor.
- Constructing `ReadableDocument` when Readability’s `hasContent()` is false.
- Handing unsanitized `ReadableDocument::$content` to a template as “safe HTML”.
- Treating `PlainText` as HTML-safe without calling `html()`, or persisting `html()` into the DB.

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- `doc/features/foundation/context/Foundational Research.md` §5
- `fivefilters/readability.php` README (`Article::hasContent()`, `ParseException`)

---

## What Readability is and is not

| Is | Is not |
|----|--------|
| Heuristic “reader view” content finder | XSS sanitizer |
| Good at ignoring page chrome | Guarantee that no odd tags remain in the winning node |
| Expects full UTF-8 HTML already in memory | Executor of page JavaScript |
| Fused metadata extractor (JSON-LD + Open Graph + other meta) | A raw `og:*` / Schema.org bag exposed to callers |

**Why a separate sanitizer still follows:** extraction optimizes for readability, not a security allowlist.

---

## Metadata: JSON-LD, Open Graph, and friends (vendor already does this)

ArticleExtractor does **not** implement a separate Open Graph or JSON-LD parser. `fivefilters/readability.php` already:

1. Optionally reads **Schema.org JSON-LD** (`disableJSONLD` defaults to `false` — leave on for MVP).
2. Scans `<meta>` tags matching Open Graph, Twitter, Dublin Core, Parsely, Weibo, etc.
3. **Normalizes** them into flat `Article` fields via first-non-empty `pick` (JSON-LD often wins, then og/dc/twitter/…).
4. Unescapes HTML entities on those string fields.
5. If no excerpt was found in metadata, may fall back to the **first paragraph** of the extracted content.
6. PHP-specific: lead image from `og:image` / `twitter:image` / `<link rel="image_src">` → `$article->image`.

Typical fusion (simplified; first non-empty wins inside the vendor):

| Our / `Article` field | Example upstream sources (among others) |
|-----------------------|------------------------------------------|
| `title` | JSON-LD headline/name → `og:title` → `twitter:title` → `dc:title` → page title heuristics |
| `excerpt` | JSON-LD description → `og:description` → `twitter:description` → meta description → (later) first `<p>` |
| `siteName` | JSON-LD publisher name → `og:site_name` |
| `byline` *(not mapped MVP)* | JSON-LD author → `dc:creator` / `author` / `article:author` |
| `publishedTime` *(not mapped MVP)* | JSON-LD `datePublished` → `article:published_time` |
| `image` *(not mapped MVP)* | `og:image` / `twitter:image` |

**Product rule:** expose **fused domain fields** (`PlainText` title/excerpt/siteName, …), not parallel raw maps like `ogTitle` vs `jsonLdTitle`. Callers must not assume a value “came from og:” specifically — only that Readability’s cascade produced it.

**Out of scope:** a second metadata stage, scraping `og:*` ourselves, or preserving the full Open Graph / JSON-LD graph for forensics.

Refs: vendor `Readability::getArticleMetadata()`, `getJSONLD()`, `getLeadImageUrl()`; README `Article` properties.

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `ArticleExtractor` | Service | Thin wrapper around Readability; map `Article` → domain types; map vendor exceptions |
| `ExtractPolicy` | Value object | Immutable knobs passed into Readability `Configuration` |
| `ExtractResult` | Value object | Discriminated result: `Ok` + `ReadableDocument`, or `NoContent` + metadata |
| `ExtractStatus` | Enum | `Ok` \| `NoContent` |
| `PlainText` | Value object | Metadata text that is **not** HTML-safe; `raw()` for storage, `html()` for display |
| `ReadableDocument` | Value object | Article content + selected metadata (`PlainText`) + `sourceUrl` |
| `ArticleExtractorException` + `ArticleExtractorError` | Error model | Hard failures only |

**Why not expose vendor `Article` on `ReadableDocument`:** public API must not couple to `fivefilters\Readability` upgrades (property hooks, DOM types). Map only fields we use; keep `Article` local to the adapter.

**Why `ExtractResult` instead of nullable `content` on one VO:** `ReadableDocument` proves content exists. A nullable `content` would let HtmlSanitizer receive a “document” with no body. Same pattern as `EncodingOutcome` (Ok / Degraded) vs weakening `Utf8Html`.

**Why not throw on no-content:** Readability 4.x returns metadata-only `Article` when no body is found (`hasContent() === false`). `ParseException` is reserved for empty input / `maxElemsToParse`. Orchestrator retains UX control via `match` on `ExtractResult`.

**Why `PlainText` for title / excerpt / siteName:** those fields are not passed through HTMLPurifier. A dedicated type reminds callers to choose storage (`raw()`) vs HTML embedding (`html()`), and strips accidental tags once at construction. Article `content` stays a separate string until `HtmlSanitizer` produces the safe fragment.

---

## `PlainText` (shared metadata type)

Used by `ReadableDocument`, `ExtractResult` (NoContent metadata), and later `SafeDocument`. Defined here because ArticleExtractor is the first producer; HtmlSanitizer only copies instances through.

**Invariant:** the internal value is UTF-8 **plain text** (tags stripped at construction). It is **not** a purified HTML fragment and must not be treated as one.

```text
final readonly class PlainText
{
    private function __construct(private string $value) {}

    /**
     * Build from untrusted metadata (title, excerpt, site name, …).
     * Applies strip_tags once, then trims. Null / empty → empty PlainText
     * (or return null from an optional? factory used for excerpt/siteName).
     */
    public static function fromUntrusted(string $value): self
    {
        $stripped = trim(strip_tags($value));
        return new self($stripped);
    }

    public static function fromUntrustedNullable(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $text = self::fromUntrusted($value);
        return $text->raw() === '' ? null : $text; // optional: keep empty as PlainText('') instead
    }

    /** UTF-8 string for DB / filesystem / JSON / logs. Do not HTML-escape before storing. */
    public function raw(): string
    {
        return $this->value;
    }

    /**
     * Escaped for HTML body text and attributes (ENT_QUOTES | ENT_SUBSTITUTE, UTF-8).
     * Do not persist the result of html() in the database.
     */
    public function html(): string
    {
        return htmlspecialchars($this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Same as raw() — avoids accidental escaped __toString in logs/JSON. */
    public function __toString(): string
    {
        return $this->value;
    }
}
```

| Method | Use | Do not use for |
|--------|-----|----------------|
| `raw()` | DB, FS, JSON APIs, logging | Embedding unescaped in HTML |
| `html()` | `<h1>`, attributes, HTML shells | Storage (would double-encode on read) |
| `__toString()` | Convenience = `raw()` | Assuming it is HTML-escaped |

**MVP non-goals for `PlainText`:** Purifier, `urlencode`, markdown, SQL escaping, a separate `stripped()` method (strip happens in `fromUntrusted`).

**Optional factory policy for empty excerpt/siteName:** prefer `?PlainText` null when missing/blank after strip; title may be `PlainText` with `raw() === ''` when Readability found no title (mirrors vendor `''`).

---

## Provenance: `sourceUrl` cascade

`Utf8Html::$sourceUrl` is copied from `FetchedPage::$requestUri` by `EncodingNormalizer` (see that spec). ArticleExtractor:

1. Propagates `sourceUrl` onto `ReadableDocument` (and onto `NoContent` metadata).
2. When `ExtractPolicy::$fixRelativeURLs` is true, passes it to Readability as `originalURL`.

Do **not** use `GuardResult::$original` (raw user input) as the link base. Do **not** invent a second `baseUrl` argument on `extract()` — the URL already rides on `Utf8Html`.

```text
SafeFetchTarget.requestUri
  → FetchedPage.requestUri
  → Utf8Html.sourceUrl
  → ReadableDocument.sourceUrl
```

---

## Result model: `ExtractStatus` + `ExtractResult`

PHP has no enum-with-payloads. Use a single immutable outcome and a status tag (same shape as `EncodingOutcome`).

```text
enum ExtractStatus
{
    case Ok;
    case NoContent;
}

final readonly class ExtractResult
{
    private function __construct(
        public ExtractStatus $status,
        public ?ReadableDocument $document = null,
        public ?PlainText $title = null,
        public ?PlainText $excerpt = null,
        public ?PlainText $siteName = null,
        public string $sourceUrl = '',
    ) {
        if ($status === ExtractStatus::Ok && $document === null) {
            throw new \InvalidArgumentException('Ok result requires ReadableDocument');
        }
        if ($status === ExtractStatus::NoContent && $document !== null) {
            throw new \InvalidArgumentException('NoContent must not carry ReadableDocument');
        }
    }

    public static function ok(ReadableDocument $document): self
    {
        return new self(
            ExtractStatus::Ok,
            document: $document,
            title: $document->title,
            excerpt: $document->excerpt,
            siteName: $document->siteName,
            sourceUrl: $document->sourceUrl,
        );
    }

    public static function noContent(
        string $sourceUrl,
        ?PlainText $title = null,
        ?PlainText $excerpt = null,
        ?PlainText $siteName = null,
    ): self {
        return new self(
            ExtractStatus::NoContent,
            document: null,
            title: $title ?? PlainText::fromUntrusted(''),
            excerpt: $excerpt,
            siteName: $siteName,
            sourceUrl: $sourceUrl,
        );
    }

    public function isOk(): bool
    {
        return $this->status === ExtractStatus::Ok;
    }

    public function isNoContent(): bool
    {
        return $this->status === ExtractStatus::NoContent;
    }
}
```

| Field | On `Ok` | On `NoContent` |
|-------|---------|----------------|
| `document` | `ReadableDocument` | `null` |
| `title` / `excerpt` / `siteName` | mirrored from document (`PlainText`) | from vendor metadata via `PlainText::fromUntrusted*` |
| `sourceUrl` | from document | from `Utf8Html::$sourceUrl` |

**Orchestrator sketch** (authoritative behaviour in `Orchestrator.md`):

```text
$result = $extractor->extract($outcome->html);

match ($result->status) {
    ExtractStatus::Ok => $sanitizer->purify($result->document),
    ExtractStatus::NoContent => PipelineNoContent::fromExtract($result, $warnings),
};
```

---

## Error model: hard failures only

`ArticleExtractorError` names *kinds* that abort extraction for this URL. Soft “no article” is **`ExtractResult::NoContent`**, not this enum.

```text
enum ArticleExtractorError
{
    case EmptyInput;
    case TooLarge;
    case Unexpected;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::EmptyInput => 'HTML input is empty',
            self::TooLarge => 'HTML document exceeds the configured element limit',
            self::Unexpected => 'Article extraction failed unexpectedly',
        };
    }
}

final class ArticleExtractorException extends \RuntimeException
{
    public function __construct(
        public readonly ArticleExtractorError $error,
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

| Case | Maps from vendor | Meaning |
|------|------------------|---------|
| `EmptyInput` | `ParseException::emptyInput()` | Whitespace-only / empty string after trim |
| `TooLarge` | `ParseException::tooManyElements(…)` | Over `maxElemsToParse` when that limit is set |
| `Unexpected` | other `\Throwable` from the adapter | Defense in depth; should be rare |

**Mapping rule:** catch `fivefilters\Readability\ParseException` (and unexpected throwables) inside `ArticleExtractor`; rethrow only `ArticleExtractorException`. Orchestrator must not import vendor exception types.

---

## Public API (implementation surface)

### `ExtractPolicy`

Immutable. Self-validating construction (non-negative limits, etc.). Named args only — no builder for MVP.

| Property | Type | Default | Meaning |
|----------|------|---------|---------|
| `debug` | `bool` | `false` | Readability `debug` → `error_log()`; when true, factory may also pass a PSR-3 logger into Readability |
| `fixRelativeURLs` | `bool` | `true` | Rewrite relative URLs using `Utf8Html::$sourceUrl` as `originalURL` |
| `charThreshold` | `int` | `500` | Min article text length before Readability retries / may yield no content (Mozilla default) |
| `maxElemsToParse` | `int` | `0` | `0` = no limit; if &gt; 0, exceeding throws `TooLarge` |

**Not exposed in MVP** (leave Readability defaults): `nbTopCandidates`, `keepClasses`, `classesToPreserve`, `disableJSONLD`, `allowedVideoRegex`, `linkDensityModifier`, `keepInlineByline`, internal flag toggles, `metadataOnly`.

**Logger:** optional PSR-3 logger may be injected on `ArticleExtractor` (constructor), not on the policy — same split as “policy knobs vs collaborators” on `HttpFetcher`. This is the **only** stage that accepts a logger in MVP. Wire it only when `ExtractPolicy::$debug` is true (factory): Readability’s PSR-3 logger, if set, emits even when `debug` is false, so omit the logger unless debugging. Orchestrator owns hop / product logging separately.

```text
final readonly class ExtractPolicy
{
    public function __construct(
        public bool $debug = false,
        public bool $fixRelativeURLs = true,
        public int $charThreshold = 500,
        public int $maxElemsToParse = 0,
    ) {
        // assert charThreshold >= 0, maxElemsToParse >= 0
    }
}
```

**About `charThreshold`:** after a candidate is chosen, if its text length is below this value, Readability retries with progressively softer flags, then may still return no content. It is **not** a hard truncate and **not** a security control. Keeping the Mozilla default (`500`) is fine unless short articles are a product requirement.

### `ArticleExtractor`

```text
final class ArticleExtractor
{
    public function __construct(
        ?ExtractPolicy $policy = null,
        // optional: ?LoggerInterface $logger = null
    ) { … }

    /**
     * @throws ArticleExtractorException
     */
    public function extract(Utf8Html $html): ExtractResult
}
```

Production builds a Readability instance from policy (+ `originalURL` = `$html->sourceUrl` when fixing relative URLs). Tests use HTML string fixtures; no network.

### `ReadableDocument`

Immutable. Constructible only from `ArticleExtractor` when the vendor article `hasContent()` is true.

| Property | Type | Meaning |
|----------|------|---------|
| `title` | `PlainText` | Article title (may be empty `raw()`) |
| `excerpt` | `?PlainText` | Description / short excerpt |
| `siteName` | `?PlainText` | Site name if found |
| `content` | `string` | Article HTML fragment (not yet Purifier-safe; always non-null) |
| `sourceUrl` | `string` | Propagated from `Utf8Html::$sourceUrl` |

```text
final readonly class ReadableDocument
{
    public function __construct(
        public PlainText $title,
        public ?PlainText $excerpt,
        public ?PlainText $siteName,
        public string $content,
        public string $sourceUrl,
    ) {}
}
```

**Not mapped in MVP:** `byline`, `dir`, `lang`, `publishedTime`, `image`, `images`, `contentElement`. Add later if the orchestrator/CLI needs them (as `PlainText` where they are plain metadata; URL-typed fields for `image` if exposed). They are still **produced by the same vendor fusion** (og / JSON-LD / …); we simply do not project them onto `ReadableDocument` yet.

**Storage vs display:** persist `$title->raw()` (and excerpt/siteName). For HTML templates use `$title->html()`. Only `content` proceeds to `HtmlSanitizer` for markup safety.

---

## Logical flow of `extract()`

```text
extract($html)
  │
  ├─ Build Readability Configuration from ExtractPolicy
  │     debug, charThreshold, maxElemsToParse
  │     fixRelativeURLs + originalURL = $html->sourceUrl (when fix on)
  │
  ├─ try parse($html->html)
  │     └─ catch ParseException empty → ArticleExtractorException(EmptyInput)
  │     └─ catch ParseException too many → ArticleExtractorException(TooLarge)
  │     └─ catch other → ArticleExtractorException(Unexpected, previous)
  │
  ├─ Map metadata:
  │     title    ← PlainText::fromUntrusted($article->title)
  │     excerpt  ← PlainText::fromUntrustedNullable($article->excerpt)
  │     siteName ← PlainText::fromUntrustedNullable($article->siteName)
  │
  ├─ If ! $article->hasContent()
  │     → ExtractResult::noContent(sourceUrl, title, excerpt, siteName)
  │
  └─ Else → ExtractResult::ok(ReadableDocument{
           title, excerpt, siteName,
           content: $article->content,
           sourceUrl: $html->sourceUrl,
         })
```

---

## Testing guidance

### Approach

- No HTTP: build `Utf8Html` fixtures with known `html` + `sourceUrl`.
- Assert `ExtractStatus` and, on Ok, that `content` excludes obvious chrome fixtures.
- Cover `NoContent` (empty-ish page / no article node).
- Cover hard failures: empty string HTML → `EmptyInput`; optional `maxElemsToParse` fixture → `TooLarge`.
- When `fixRelativeURLs` is true, assert a relative `href` becomes absolute against `sourceUrl`.
- Unit-test `PlainText`: `fromUntrusted` strips tags; `raw()` vs `html()` (e.g. `&` → `&amp;`); do not store `html()` output in persistence tests.

### Suggested cases

| Case | Input sketch | Expected |
|------|--------------|----------|
| Simple article | `<article><p>…long enough…</p></article>` + chrome | `Ok`, content has paragraph, not nav |
| Relative link | `<a href="/x">` + `sourceUrl=https://ex.com/a` | absolute `https://ex.com/x` in content |
| No article | mostly empty / nav-only short page | `NoContent` |
| Empty HTML | `""` / whitespace | `ArticleExtractorException` + `EmptyInput` |
| Metadata only path | page with title meta but no body | `NoContent`, title still set if Readability found it |
| Title with tags | meta title contains `<b>Hi</b>` | `title->raw() === 'Hi'`; `title->html()` escaped if needed |

---

## Out of scope for ArticleExtractor

- Charset detection / conversion (`EncodingNormalizer`).
- XSS sanitization / URI scheme policy (`HtmlSanitizer`).
- Fetching, redirects, SSRF (`UrlGuard` / `HttpFetcher`).
- Executing JavaScript / headless browser (research limitation).
- Exposing full Readability `Configuration` or vendor `Article` on the public API.
- A separate Open Graph / JSON-LD / meta scraper (vendor already fuses these into `Article` fields).
- Exposing raw `og:*` keys or the full JSON-LD graph alongside fused fields.
- Product mapping of `NoContent` / encoding `Degraded` (Orchestrator — already decided there).
- A `SafeHtmlFragment` wrapper for article body (optional later; MVP keeps purified HTML as `string` on `SafeDocument`).
