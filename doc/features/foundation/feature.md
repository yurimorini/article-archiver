# Feature: Foundation pipeline (remote URL → cleaned article HTML)

## Goal

Turn an untrusted input URL into a **UTF-8, sanitized article HTML fragment** suitable to store or display.

```text
input URL string
  → UrlGuard           → SafeFetchTarget (+ GuardResult)
  → HttpFetcher        → FetchedPage
  → EncodingNormalizer → EncodingOutcome (Ok | Degraded + Utf8Html)
  → ArticleExtractor   → ExtractResult (Ok + ReadableDocument | NoContent)
  → HtmlSanitizer      → SafeDocument

Orchestrator (composition root) wires the five stages above and returns
  PipelineSuccess | PipelineNoContent  (or throws OrchestratorException)
```

Design principle across stages: **parse, don’t validate** — each step produces a richer typed result (or a tagged failure) so unsafe values cannot slip through as bare `string`s.

Provenance cascade: `requestUri` / `sourceUrl` rides on `FetchedPage` → `Utf8Html` → `ReadableDocument` → `SafeDocument` so relative URL fixing and logging always have the page URL beside the HTML.

Metadata (`title`, `excerpt`, `siteName`) uses shared **`PlainText`**: `raw()` for DB/FS/JSON, `html()` for HTML embedding; article body HTML is purified separately into `SafeDocument::$html`.

Research and threat model: `[context/Foundational Research.md](context/Foundational%20Research.md)`.

---



## Stages (classes)


| Stage                  | Responsibility                                                                                                   | Output                                        | Spec                                                         |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------- | ------------------------------------------------------------ |
| **UrlGuard**           | Normalize URL; scheme/host/credentials policy; SSRF DNS + public-IP check; keep IPs for pin                      | `SafeFetchTarget` / `GuardResult`             | `[specs/UrlGuard.md](specs/UrlGuard.md)`                     |
| **HttpFetcher**        | Single Guzzle GET with DNS pin, timeouts, Content-Type + size gates; **no redirects**; **no** charset transcoder | `FetchedPage`                                 | `[specs/HttpFetcher.md](specs/HttpFetcher.md)`               |
| **EncodingNormalizer** | Charset cascade → UTF-8; quality `Ok` \| `Degraded`; propagate `sourceUrl`                                       | `EncodingOutcome` (`Utf8Html`)                | `[specs/EncodingNormalizer.md](specs/EncodingNormalizer.md)` |
| **ArticleExtractor**   | Readability main-content + fused metadata (JSON-LD / og / …); soft `NoContent` vs hard parse failures | `ExtractResult` / `ReadableDocument`          | `[specs/ArticleExtractor.md](specs/ArticleExtractor.md)`     |
| **HtmlSanitizer**      | HTMLPurifier policy (UTF-8 fixed, tight URI schemes, debug cache/errors)                                         | `SafeDocument`                                | `[specs/HtmlSanitizer.md](specs/HtmlSanitizer.md)`           |
| **Orchestrator**       | Compose stages; map exceptions; `PipelineSuccess` / `PipelineNoContent`; CLI/library entry                        | `PipelineSuccess` \| `PipelineNoContent` \| throws `OrchestratorException` | `[specs/Orchestrator.md](specs/Orchestrator.md)`             |


Stack (from research / `composer.json`): Guzzle, `craftcms/url-validator` (planned), `fossar/guzzle-transcoder` helpers / Transcoder for conversion (not as fetch middleware), `fivefilters/readability.php`, `ezyang/htmlpurifier`.

**Runtime baseline** (locked in `composer.json`):

| Requirement | Why |
|-------------|-----|
| PHP `^8.4` | `fivefilters/readability.php` 4.x requires ≥8.4 |
| `ext-dom` | Readability DOM parsing |
| `ext-mbstring` | Readability + EncodingNormalizer charset work |
| `ext-curl` | HttpFetcher DNS pinning via Guzzle cURL / `CURLOPT_RESOLVE` |

### Explicit MVP non-goals

- Redirect following (fail closed on `3xx`; per-hop re-guard later)
- `robots.txt`, rate limiting, headless browser
- Forensic preservation of original response bytes as the working pipeline copy

---



## Current status


| Area              | State                                                                                                                                                                                                 |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Specs             | **Done** for UrlGuard, HttpFetcher, EncodingNormalizer, ArticleExtractor, HtmlSanitizer, Orchestrator.                                                                                                 |
| Application code  | `src/` and `tests/` are **empty** (PSR-4 `Yumo\LogRead\` ready, no classes yet).                                                                                                                      |
| Spike / prototype | `bin/run` sketches fetch + Readability + Purifier, but mixes stages, follows redirects, uses GuzzleTranscoder middleware, and has incomplete UrlGuard/pinning — **not** the target design.            |
| Dependencies      | Present: Guzzle, Readability, HTMLPurifier, guzzle-transcoder. **Missing from Composer:** `craftcms/url-validator` (required by UrlGuard production adapter). PHPUnit is available as dev dependency. |


---



## Implementation phases

Each phase should land as a **testable vertical slice** of that stage (unit tests per the stage’s testing guidance), then move on. Do not wire the full CLI until Phase 6 unless needed for smoke checks.

### Phase 0 — Project skeleton (short)

- Confirm package layout under `src/` (e.g. one folder or namespace segment per stage).
- Confirm runtime baseline: PHP `^8.4`, `ext-dom`, `ext-mbstring`, `ext-curl` (already declared in `composer.json`).
- Add `craftcms/url-validator` to Composer.
- Optionally replace or retire `bin/run` once Orchestrator exists; until then treat it as non-authoritative scratch only.



### Phase 1 — UrlGuard

Implement per `[specs/UrlGuard.md](specs/UrlGuard.md)`:

- Types: `SafeFetchTarget`, `GuardResult`, `UrlGuardException` + `UrlGuardError`, `SsrfUrlValidator` + Craft adapter.
- Local policy (trim, `parse_url`, `http`/`https`, host required, reject credentials) then SSRF validate → public IPs.
- Tests with a stub/fake `SsrfUrlValidator` (no real DNS in unit tests).

**Exit criteria:** `guard(string): GuardResult` rejects policy/SSRF cases and returns pin-ready coordinates on success.

### Phase 2 — HttpFetcher

Implement per `[specs/HttpFetcher.md](specs/HttpFetcher.md)`:

- Types: `FetchPolicy`, `FetchedPage`, `HttpFetcherException` + `HttpFetcherError`.
- Guzzle GET with `CURLOPT_RESOLVE` from `SafeFetchTarget`; `allow_redirects => false`; timeouts; Content-Type + max-body gates (header + stream).
- Inject `ClientInterface` / `MockHandler` in tests.

**Exit criteria:** `fetch(SafeFetchTarget): FetchedPage`; redirects and oversize/non-HTML fail closed with tagged errors.

### Phase 3 — EncodingNormalizer

Implement per `[specs/EncodingNormalizer.md](specs/EncodingNormalizer.md)`:

- Types: `Utf8Html` (includes `sourceUrl` from `FetchedPage::$requestUri`), `EncodingOutcome`, `EncodingQuality`, `EncodingError`, hard-failure exception only when UTF-8 is impossible.
- Cascade: BOM → HTTP charset → meta prescan → UTF-8 validity → optional detect; prefer HTTP over meta; no Guzzle transcoder middleware.
- Tests with fixture byte strings (declared charset, conflict, BOM, degraded path); assert `sourceUrl` propagation.

**Exit criteria:** `normalize(FetchedPage): EncodingOutcome`; extractor boundary uses only `$outcome->html` (`Utf8Html`).

### Phase 4 — ArticleExtractor

Implement per `[specs/ArticleExtractor.md](specs/ArticleExtractor.md)`:

- Types: `ExtractPolicy`, `ExtractResult` / `ExtractStatus`, `PlainText`, `ReadableDocument`, `ArticleExtractorException` + `ArticleExtractorError`.
- Thin wrapper around `fivefilters/readability.php`; map soft no-content (including empty/whitespace HTML) to `ExtractResult::NoContent`; hard `ParseException` (element limit) → tagged exception.
- Rely on vendor fusion for JSON-LD / Open Graph / other meta → `title` / `excerpt` / `siteName` (no separate OG scraper).
- Propagate `sourceUrl`; use it as Readability `originalURL` when `fixRelativeURLs` is on.
- Map title/excerpt/siteName through `PlainText::fromUntrusted*` (strip tags once).
- Default `ExtractPolicy::$maxElemsToParse = 30000` (resource guard; `0` = unlimited opt-out); cover `TooLarge` in tests.
- Unit tests with small HTML fixtures (chrome vs article body, relative links, empty input, PlainText escape/strip).

**Exit criteria:** `Utf8Html` in → `ExtractResult` with `ReadableDocument` or typed `NoContent`; oversize DOM → `ArticleExtractorException` + `TooLarge`.

### Phase 5 — HtmlSanitizer

Implement per `[specs/HtmlSanitizer.md](specs/HtmlSanitizer.md)`:

- Types: `PurifyPolicy`, `SafeDocument` (copies `PlainText` metadata), `HtmlSanitizerException` + `HtmlSanitizerError`.
- Purifier: `Core.Encoding=UTF-8` fixed; `URI.AllowedSchemes` from policy (MVP allowlist `http`/`https`/`mailto` only; hard-reject `javascript`/`data`/`file`); production definition cache via `PipelineOptions::$purifierCachePath` / constructor only; `debug` maps to cache-off / optional CollectErrors only.
- Tests for script/iframe/`javascript:` stripping and allowed markup retention; assert metadata `PlainText` is not re-encoded by Purifier.

**Exit criteria:** `ReadableDocument` in → `SafeDocument` under the documented policy.

### Phase 6 — Orchestrator + entrypoint

1. Implement per `[specs/Orchestrator.md](specs/Orchestrator.md)` (already written): stage order, `PipelineSuccess` / `PipelineNoContent`, exception wrap, factory + CLI API.
2. Wire UrlGuard → HttpFetcher → EncodingNormalizer → ArticleExtractor → HtmlSanitizer.
3. Replace spike `bin/run` with a thin CLI that calls the orchestrator.
4. One integration/smoke path (mocked HTTP or a fixed public URL) proving the full happy path.

**Exit criteria:** one public “fetch article from URL” use case end-to-end under MVP policies.

### Phase 7 — Hardening (after MVP works)

Only after Phase 6 is green; align with research “Deferred”:

- Redirect-safe loop (per-hop UrlGuard + re-pin).
- IDN hosts: accept + normalize to punycode (`ext-intl`) end-to-end (UrlGuard `requestUri` / pin / SNI).
- Stricter `HTML.Allowed` once display needs are known.
- Purifier definition cache path tuning for real runs.
- Optional: batch politeness (`robots.txt`, delays) if multi-URL use appears.

---



## Suggested build order (summary)

```text
0 skeleton + url-validator
1 UrlGuard
2 HttpFetcher
3 EncodingNormalizer
4 ArticleExtractor
5 HtmlSanitizer
6 Orchestrator + CLI
7 deferred hardening
```

Phases 1–6 should be implemented as specified in `feature.md` and the stage specs.
