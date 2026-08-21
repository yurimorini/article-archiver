# Orchestrator

This document is both a **design spec** (what to implement) and a **walkthrough** of why the Orchestrator exists and how the foundation stages compose into one public use case.

## Where Orchestrator sits

The larger pipeline turns a remote page into cleaned article HTML:

```text
input URL string
  → UrlGuard
  → HttpFetcher
  → EncodingNormalizer
  → ArticleExtractor
  → HtmlSanitizer
  → Orchestrator   (this document — wires the above)
```

Orchestrator is the **composition root** for the library/CLI entry. It does **not** reimplement SSRF, HTTP, charset, Readability, or Purifier. It owns stage order, soft-outcome UX, exception wrapping, and hop-level logging.

```text
string $url → Orchestrator::fetchArticle
                ├─ PipelineSuccess { SafeDocument, warnings }
                └─ PipelineNoContent { reason, metadata, warnings }
             throws OrchestratorException
```

---

## Problem: five correct stages still need a product boundary

Each stage has a clear contract. Callers still need:

| Need | Why |
|------|-----|
| One method | Library/CLI should not wire five services by hand every time |
| Soft vs hard | Encoding can be lossy; extraction can find no article; Purifier can leave an empty fragment — none of these are the same as “URL rejected” |
| Stable errors | External code wants one exception type + a coarse enum, not five stage exception types in every `catch` |
| Observability | Hop success / soft warnings / failures need a logger without forcing Monolog |

Orchestrator’s job: **parse** an untrusted URL string into either a usable article (`PipelineSuccess`) or a typed empty outcome (`PipelineNoContent`), or fail closed with `OrchestratorException`.

---

## Design principle: parse, don’t validate

**Validate** = “did it work?” → bool / null document.

**Parse** = produce a discriminated result:

- `PipelineSuccess` — invariant: non-empty purified HTML in `SafeDocument`
- `PipelineNoContent` — invariant: nothing useful to store as article body; `reason` explains why
- `OrchestratorException` — hard abort for this URL

Illegal states:

- Returning `PipelineSuccess` with empty/whitespace-only `SafeDocument::$html`
- Throwing on Extract `NoContent` or empty post-purify HTML (those are soft)
- Letting stage exceptions (`UrlGuardException`, …) escape unwrapped
- Following redirects or retrying timeouts inside Orchestrator

Refs:

- [Parse, don’t validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- Stage specs under `doc/features/foundation/specs/`
- `doc/features/foundation/feature.md` Phase 6

---

## Decisions

| Topic | Choice |
|-------|--------|
| Retry on timeout | **None** — `HttpFetcher` fails closed on `Transport`; Orchestrator does not retry |
| Redirects | Unchanged stage policy — fail closed on `3xx` |
| Encoding `Degraded` | **Continue**; append `PipelineWarning`; log warning |
| Extract `NoContent` | **`PipelineNoContent`** (`ExtractNoContent`); skip sanitizer |
| Empty HTML after Purifier | **`PipelineNoContent`** (`SanitizedEmpty`); not an exception |
| Hard stage failures | Wrap in **`OrchestratorException`** + **`OrchestratorError`** (one case per stage) + `$previous` |
| Public wiring | **`OrchestratorFactory` / `PipelineOptions`** for defaults; **public constructor** for tests/custom DI |
| Logging | PSR-3 on Orchestrator **and** stages; **`Psr\Log\NullLogger`** when omitted |
| Tests | Prefer **integration** (real stages + mocked HTTP), not mocks of every stage |

---

## Roles of the types

| Type | Kind | Responsibility |
|------|------|----------------|
| `Orchestrator` | Service | Run stage sequence; map soft/hard outcomes; hop logging |
| `OrchestratorFactory` | Factory | Opaque defaults + `PipelineOptions` → wired `Orchestrator` |
| `PipelineOptions` | Value object | Small set of public knobs (timeouts, sizes, logger, …) mapped into stage policies |
| `PipelineSuccess` | Result | Non-empty `SafeDocument` + warnings |
| `PipelineNoContent` | Result | Empty product outcome + `PipelineEmptyReason` + metadata + warnings |
| `PipelineEmptyReason` | Enum | Why there is no article body to store |
| `PipelineWarning` + `PipelineWarningCode` | Soft signal | Extensible list; for now: encoding degraded only |
| `OrchestratorException` + `OrchestratorError` | Error model | One public exception; enum = which stage failed |

**Why not a single result VO with nullable `document`:** forces null checks and allows illegal combinations. Two result types keep the happy path null-free.

**Why empty post-purify joins `PipelineNoContent`:** product-wise both mean “do not store an article body”. `reason` preserves diagnostics without a third result type.

**Why coarse `OrchestratorError`:** CLI/HTTP status can `match` five cases. Fine detail stays on `$previous` (`HttpFetcherException::$error`, etc.).

---

## Public API

### `Orchestrator`

```text
final class Orchestrator
{
    public function __construct(
        private UrlGuard $urlGuard,
        private HttpFetcher $httpFetcher,
        private EncodingNormalizer $encodingNormalizer,
        private ArticleExtractor $articleExtractor,
        private HtmlSanitizer $htmlSanitizer,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @throws OrchestratorException
     */
    public function fetchArticle(string $url): PipelineSuccess|PipelineNoContent
}
```

Constructor is the **escape hatch** (tests, custom wiring). Everyday callers use the factory.

### Factory + options (opaque defaults)

```text
final readonly class PipelineOptions
{
    public function __construct(
        public ?FetchPolicy $fetchPolicy = null,
        public ?ExtractPolicy $extractPolicy = null,
        public ?PurifyPolicy $purifyPolicy = null,
        public ?LoggerInterface $logger = null,
        // optional: UrlGuard collaborator overrides for advanced hosts
    ) {}
}

final class OrchestratorFactory
{
    public static function create(?PipelineOptions $options = null): Orchestrator
    {
        $logger = $options?->logger ?? new NullLogger();
        // wire stages with default or overridden policies; pass $logger into stages that accept it
        return new Orchestrator(/* … */, $logger);
    }
}
```

| Call site | Usage |
|-----------|--------|
| CLI / normal library use | `OrchestratorFactory::create()` or `create(new PipelineOptions(…))` |
| Unit/integration tests | `new Orchestrator($guard, $fetcher, …)` with mocked Guzzle / fake SSRF |
| App container | Either factory or explicit constructor injection |

**No fluent policy builder** — same rationale as `FetchPolicy` (small fixed knob set).

### Sketch of `fetchArticle` (main stays flat)

Construction of `PipelineNoContent` uses **static factories on the result type**, not repeated field mapping in the main method.

```text
public function fetchArticle(string $url): PipelineSuccess|PipelineNoContent
{
    $target = $this->guardUrl($url);
    $page = $this->fetchPage($target);
    [$html, $warnings] = $this->normalizeEncoding($page);

    $extract = $this->articleExtractor->extract($html);
    // log extract outcome at this hop (or inside extractArticle private)
    if ($extract->isNoContent()) {
        return PipelineNoContent::fromExtract($extract, $warnings);
    }

    $safe = $this->htmlSanitizer->purify($extract->document);
    if (trim($safe->html) === '') {
        return PipelineNoContent::fromSanitizedEmpty($safe, $warnings);
    }

    return new PipelineSuccess($safe, $warnings);
}
```

Private methods (`guardUrl`, `fetchPage`, `normalizeEncoding`, …) own:

1. Calling the stage
2. Hop logging
3. `catch` → `OrchestratorException` wrap

Unexpected `\Throwable` → `OrchestratorError::Unexpected`.

---

## Result model

### `PipelineSuccess`

```text
final readonly class PipelineSuccess
{
    /**
     * @param list<PipelineWarning> $warnings
     */
    public function __construct(
        public SafeDocument $document,
        public array $warnings = [],
    ) {
        // assert trim($document->html) !== ''
    }

    public function isDegraded(): bool
    {
        return $this->warnings !== [];
    }
}
```

Invariant: `$document->html` is non-empty after trim.

### `PipelineEmptyReason` + `PipelineNoContent`

```text
enum PipelineEmptyReason
{
    case ExtractNoContent; // ArticleExtractor: no ReadableDocument
    case SanitizedEmpty;   // SafeDocument returned but html empty/whitespace
}

final readonly class PipelineNoContent
{
    /**
     * @param list<PipelineWarning> $warnings
     */
    private function __construct(
        public PipelineEmptyReason $reason,
        public string $sourceUrl,
        public PlainText $title,
        public ?PlainText $excerpt,
        public ?PlainText $siteName,
        public array $warnings = [],
    ) {}

    /**
     * @param list<PipelineWarning> $warnings
     */
    public static function fromExtract(ExtractResult $result, array $warnings = []): self
    {
        // require $result->isNoContent(); map title/excerpt/siteName/sourceUrl; reason = ExtractNoContent
    }

    /**
     * @param list<PipelineWarning> $warnings
     */
    public static function fromSanitizedEmpty(SafeDocument $document, array $warnings = []): self
    {
        // reason = SanitizedEmpty; copy metadata + sourceUrl from $document
    }
}
```

| Reason | When | Sanitizer ran? |
|--------|------|----------------|
| `ExtractNoContent` | `ExtractResult::NoContent` | No |
| `SanitizedEmpty` | `trim(SafeDocument::$html) === ''` | Yes |

Caller pattern:

```text
if ($result instanceof PipelineNoContent) {
    // do not store article body; optional: log $result->reason
    return;
}
store($result->document);
```

### Warnings

```text
enum PipelineWarningCode
{
    case EncodingDegraded;
}

final readonly class PipelineWarning
{
    public function __construct(
        public PipelineWarningCode $code,
        public string $message,
        public ?\Throwable $previous = null,
    ) {}
}
```

Map `EncodingOutcome::Degraded` → one `PipelineWarning` (`EncodingDegraded`, message from `EncodingError`, optional `$previous`). Warnings may appear on **both** `PipelineSuccess` and `PipelineNoContent` (e.g. degraded encoding then empty purify).

---

## Error model

```text
enum OrchestratorError
{
    case UrlGuard;
    case Fetch;
    case Encoding;
    case Extract;
    case Sanitize;
    case Unexpected;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::UrlGuard => 'URL guard rejected the input URL',
            self::Fetch => 'HTTP fetch failed',
            self::Encoding => 'Character encoding normalization failed',
            self::Extract => 'Article extraction failed',
            self::Sanitize => 'HTML sanitization failed',
            self::Unexpected => 'Article pipeline failed unexpectedly',
        };
    }
}

final class OrchestratorException extends \RuntimeException
{
    public function __construct(
        public readonly OrchestratorError $error,
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

| Catch | Wrap as |
|-------|---------|
| `UrlGuardException` | `UrlGuard` |
| `HttpFetcherException` | `Fetch` |
| `EncodingNormalizerException` | `Encoding` |
| `ArticleExtractorException` | `Extract` |
| `HtmlSanitizerException` | `Sanitize` |
| other `\Throwable` | `Unexpected` |

Do **not** wrap soft outcomes. Detail for logs/metrics: `$e->getPrevious()` (stage exception + its error enum).

---

## Pipeline flow

```text
fetchArticle(rawUrl)
  │
  ├─ guardUrl
  │     UrlGuard->guard
  │     log ok / wrap UrlGuardException
  │     → SafeFetchTarget
  │
  ├─ fetchPage
  │     HttpFetcher->fetch   (single attempt; no retry)
  │     log ok / wrap HttpFetcherException
  │     → FetchedPage
  │
  ├─ normalizeEncoding
  │     EncodingNormalizer->normalize
  │     hard fail → wrap EncodingNormalizerException
  │     Degraded → log warning + PipelineWarning(EncodingDegraded)
  │     Ok → log info/debug
  │     → Utf8Html + warnings[]
  │
  ├─ extract
  │     ArticleExtractor->extract
  │     hard fail → wrap ArticleExtractorException
  │     NoContent → log notice → PipelineNoContent::fromExtract(…)  [STOP]
  │     Ok → ReadableDocument
  │
  └─ purify
        HtmlSanitizer->purify
        hard fail → wrap HtmlSanitizerException
        trim(html) === '' → PipelineNoContent::fromSanitizedEmpty(…)
        else → PipelineSuccess(SafeDocument, warnings)
```

```text
                    ┌─ PipelineSuccess ─────────────┐
string URL ─────────┤                               ├─ store/display html
                    └─ PipelineNoContent ───────────┘─ skip body; reason in metadata
                              │
                    OrchestratorException (hard)
```

---

## Logging

| Layer | Role |
|-------|------|
| Orchestrator | Hop boundaries: success, soft branch, wrap-before-throw |
| Stages (optional PSR-3) | Closer to I/O / vendor detail (same logger instance from factory) |
| Absent logger | `NullLogger` — no custom minimal logger |

Avoid double-reporting the same failure as `error` on both stage and orchestrator; prefer stage `debug`/`info` detail and orchestrator `warning`/`error` at the product boundary, or the reverse — pick one convention in implementation and stay consistent.

---

## CLI / library entry

Thin CLI (`bin/run` replacement) should:

1. Build via `OrchestratorFactory::create(…)` (optional CLI flags → `PipelineOptions`)
2. Call `fetchArticle`
3. `match` / `instanceof` on success vs no-content; print or write HTML
4. `catch (OrchestratorException)` → non-zero exit mapped from `OrchestratorError`

Orchestrator must not parse argv; CLI stays outside the domain service.

---

## Testing guidance

Prefer **integration** tests of `Orchestrator` with real stages:

- Inject `HttpFetcher` backed by Guzzle `MockHandler` (or equivalent)
- Stub/fake `SsrfUrlValidator` for UrlGuard (no real DNS)
- Fixtures: happy HTML, encoding-degraded bytes, Readability no-content page, markup that purifies to empty, transport/HTTP failures

Assert:

| Scenario | Expect |
|----------|--------|
| Happy path | `PipelineSuccess`, non-empty `html` |
| Encoding degraded + article | `PipelineSuccess` + `EncodingDegraded` warning |
| Extract no content | `PipelineNoContent` + `ExtractNoContent` |
| Purify → empty | `PipelineNoContent` + `SanitizedEmpty` |
| Guard / fetch / hard encoding / extract / sanitize fail | `OrchestratorException` with matching `OrchestratorError` and stage `$previous` |

Do **not** require mocking `ArticleExtractor` / `HtmlSanitizer` for the primary orchestrator suite unless branching logic grows enough to justify focused unit tests of private helpers.

---

## Out of scope

- Timeout retry / backoff
- Redirect following or per-hop re-guard
- Fluent `Factory::withX()->withY()->build()`
- Fine-grained `OrchestratorError` cases mirroring every stage enum value
- Exposing intermediate `FetchedPage` / `Utf8Html` on the public result
- Treating Purifier tag-stripping (non-empty result) as a warning

---

## Implementation checklist

- [ ] `PipelineWarningCode` / `PipelineWarning`
- [ ] `PipelineEmptyReason`, `PipelineNoContent` (`fromExtract`, `fromSanitizedEmpty`), `PipelineSuccess`
- [ ] `OrchestratorError` / `OrchestratorException`
- [ ] `Orchestrator` with private hop methods (log + wrap)
- [ ] `PipelineOptions` + `OrchestratorFactory::create`
- [ ] PSR-3 logger defaulting to `NullLogger` on orchestrator and stage constructors that accept a logger
- [ ] Integration tests per table above
- [ ] Thin CLI calling the factory

**Exit criteria:** one public `fetchArticle(string): PipelineSuccess|PipelineNoContent` path end-to-end under the policies above, with hard failures only as `OrchestratorException`.
