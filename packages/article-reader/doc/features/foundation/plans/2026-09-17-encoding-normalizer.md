# EncodingNormalizer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Phase 3 EncodingNormalizer so a `FetchedPage` of opaque HTML bytes becomes an `EncodingOutcome` carrying `Utf8Html` (quality `Ok` or `Degraded`) or a tagged `EncodingNormalizerException` when even lossy UTF-8 is impossible.

**Architecture:** Detection is a local cascade owned by `EncodingNormalizer` (UTF-8 BOM, then HTTP `Content-Type` charset, then a 1024-byte HTML meta prescan, then UTF-8 validity, then optional `mb_detect_encoding`). HTTP charset wins over a conflicting meta declaration. Conversion uses `Ddeboer\Transcoder\Transcoder` (from `fossar/transcoder`, already pulled in by `fossar/guzzle-transcoder`) behind a small `Utf8Converter` port so tests can stub both strict and lossy conversion. Header and meta parsing reuse `Fossar\GuzzleTranscoder\ContentTypeExtractor` as a library — never as Guzzle middleware, and never `GuzzleTranscoder::convertResponse()` (that helper prefers body charset over header). Types live under `Yumo\LogRead\EncodingNormalizer\`.

**Tech Stack:** PHP `^8.5`, PHPUnit `^11`, `ext-mbstring`, `fossar/guzzle-transcoder` `^0.3.2` (`ContentTypeExtractor`), `fossar/transcoder` `^3.0` (`Ddeboer\Transcoder\TranscoderInterface`; this package `replace`s `ddeboer/transcoder`), PSR-4 `Yumo\LogRead\` → `src/`.

**Spec:** `doc/features/foundation/specs/EncodingNormalizer.md` (also `doc/features/foundation/feature.md` Phase 3)

## Global Constraints

- PHP `^8.5`; `ext-mbstring` is a hard Composer requirement (validity checks, BOM-safe conversion, `mb_scrub`).
- Namespace root: `Yumo\LogRead\` → `src/`; tests: `Yumo\LogRead\Tests\` → `tests/`.
- Documentation, identifiers, commit messages, and PHPDoc in **English**. Follow `.cursor/rules/php-docs.mdc`: complete sentences; do **not** describe types as “the third pipeline stage”.
- **Parse, don’t validate:** `normalize()` returns `EncodingOutcome` with a `Utf8Html`. `Utf8Html::$html` is always valid UTF-8 with no leading UTF-8 BOM. Quality (`Ok` / `Degraded`) lives on the outcome, not by weakening `Utf8Html`.
- **HTTP charset wins over meta** when both exist (HTML5-oriented project policy).
- **No Guzzle transcoder middleware** and do **not** call `Fossar\GuzzleTranscoder\GuzzleTranscoder::convertResponse()` — it prefers body charset over header, which disagrees with this spec.
- **Do not rewrite** HTML meta/header charset declarations in the body (`replaceContent: false` equivalent).
- Soft encoding problems return `EncodingOutcome::degraded(...)`; throw `EncodingNormalizerException` only when no `Utf8Html` can be produced.
- `Utf8Html::$sourceUrl` is copied from `FetchedPage::$requestUri` (not `GuardResult::$original`). This stage does not re-validate URLs.
- Unit tests must not perform HTTP: build `FetchedPage` fixtures with crafted `body` + `contentType`.
- **Environment note for this plan's execution:** Docker is unavailable in the current environment, so every command below runs PHP/Composer directly on the host (`vendor/bin/phpunit`, `composer test`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`) instead of the `./bin/*` Docker wrappers. When Docker is available again, `./bin/quality` must still pass on the final code — the commands are equivalent, only the execution wrapper differs.
- Do not implement `ArticleExtractor`, `HtmlSanitizer`, or `Orchestrator` in this plan.
- Leave `src/index.php` (scratch) untouched; do not replace it until Orchestrator (Phase 6).

---

## File Structure

| Path | Responsibility |
|------|----------------|
| `src/EncodingNormalizer/EncodingError.php` | Warning / hard-failure kind (`Undeclared`, `Unsupported`, `Conversion`) + `defaultMessage()` |
| `src/EncodingNormalizer/EncodingNormalizerException.php` | Hard failure only: no `Utf8Html` could be produced |
| `src/EncodingNormalizer/EncodingQuality.php` | Discriminant `Ok` \| `Degraded` |
| `src/EncodingNormalizer/EncodingOutcome.php` | Immutable sum type: always `Utf8Html`; factories enforce warning invariants |
| `src/EncodingNormalizer/EncodingSource.php` | Diagnostic: where the source encoding was taken from |
| `src/EncodingNormalizer/Utf8Html.php` | Immutable UTF-8 HTML + `sourceUrl` + encoding diagnostics; construction enforces invariants |
| `src/EncodingNormalizer/Utf8Converter.php` | Port: strict convert + lossy convert to UTF-8 |
| `src/EncodingNormalizer/TranscoderUtf8Converter.php` | Production adapter: `TranscoderInterface` for strict convert; `mb_convert_encoding` / `mb_scrub` for lossy |
| `src/EncodingNormalizer/EncodingNormalizer.php` | Cascade + convert + post-condition; returns `EncodingOutcome` |
| `tests/EncodingNormalizer/EncodingNormalizerExceptionTest.php` | Default vs custom message, `$previous` |
| `tests/EncodingNormalizer/EncodingOutcomeTest.php` | `ok()` / `degraded()` factories and quality helpers |
| `tests/EncodingNormalizer/Utf8HtmlTest.php` | Field storage and invariant rejection |
| `tests/EncodingNormalizer/EncodingNormalizerTest.php` | `normalize()` cascade, sourceUrl, Ok / Degraded / throw |
| `tests/EncodingNormalizer/FakeUtf8Converter.php` | Test double that fails strict and/or lossy conversion |
| `composer.json` / `composer.lock` | Direct require of `fossar/transcoder` `^3.0` (already a transitive dependency) |

---

### Task 1: `EncodingError` + `EncodingNormalizerException`

**Files:**
- Create: `src/EncodingNormalizer/EncodingError.php`
- Create: `src/EncodingNormalizer/EncodingNormalizerException.php`
- Test: `tests/EncodingNormalizer/EncodingNormalizerExceptionTest.php`

**Interfaces:**
- Consumes: nothing from later tasks
- Produces:
  - `enum EncodingError { case Undeclared; case Unsupported; case Conversion; public function defaultMessage(): string }`
  - `final class EncodingNormalizerException extends \RuntimeException { public function __construct(public readonly EncodingError $error, string $message = '', ?\Throwable $previous = null) }`
  - Default messages (verbatim from the spec):
    - `Undeclared` → `Character encoding could not be determined`
    - `Unsupported` → `Character encoding is not supported for conversion`
    - `Conversion` → `Character encoding conversion to UTF-8 failed`

- [ ] **Step 1: Write the failing test**

Create `tests/EncodingNormalizer/EncodingNormalizerExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizerException;

final class EncodingNormalizerExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(EncodingError $error, string $expected): void
    {
        $e = new EncodingNormalizerException($error);
        self::assertSame($expected, $e->getMessage());
        self::assertSame($error, $e->error);
    }

    /**
     * @return array<string, array{EncodingError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'undeclared' => [
                EncodingError::Undeclared,
                'Character encoding could not be determined',
            ],
            'unsupported' => [
                EncodingError::Unsupported,
                'Character encoding is not supported for conversion',
            ],
            'conversion' => [
                EncodingError::Conversion,
                'Character encoding conversion to UTF-8 failed',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $e = new EncodingNormalizerException(EncodingError::Conversion, 'iconv refused', $previous);
        self::assertSame('iconv refused', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter EncodingNormalizerExceptionTest`

Expected: FAIL with class not found (`EncodingNormalizerException` or `EncodingError`).

- [ ] **Step 3: Write minimal implementation**

Create `src/EncodingNormalizer/EncodingError.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Kind of encoding problem reported as a degraded-outcome warning or on a hard-failure exception.
 *
 * Soft problems (lossy conversion, undeclared charset) use this enum on
 * `EncodingOutcome::$warning`. The exception path uses the same cases only when
 * UTF-8 HTML cannot be produced at all.
 */
enum EncodingError
{
    /** No BOM, HTTP charset, or meta declaration, and UTF-8 validity / guess was weak or failed. */
    case Undeclared;

    /** The declared encoding name is unknown on this platform. */
    case Unsupported;

    /** Strict conversion to UTF-8 failed or the source bytes were not valid in the declared encoding. */
    case Conversion;

    /**
     * Returns the standard message for this kind of problem when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Undeclared => 'Character encoding could not be determined',
            self::Unsupported => 'Character encoding is not supported for conversion',
            self::Conversion => 'Character encoding conversion to UTF-8 failed',
        };
    }
}
```

Create `src/EncodingNormalizer/EncodingNormalizerException.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Thrown when `EncodingNormalizer` cannot produce UTF-8 HTML at all.
 *
 * Inspect `$error` to tell undeclared, unsupported, and conversion failures apart.
 * Lossy-but-valid UTF-8 is not this exception: that is `EncodingOutcome::degraded()`.
 */
final class EncodingNormalizerException extends \RuntimeException
{
    public function __construct(
        /** Why UTF-8 HTML could not be produced. */
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

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter EncodingNormalizerExceptionTest`

Expected: PASS (4 tests: 3 default messages + custom message).

- [ ] **Step 5: Commit**

```bash
git add src/EncodingNormalizer/EncodingError.php src/EncodingNormalizer/EncodingNormalizerException.php tests/EncodingNormalizer/EncodingNormalizerExceptionTest.php
git commit -m "$(cat <<'EOF'
feat(encoding-normalizer): add encoding error enum and hard-failure exception

EOF
)"
```

---

### Task 2: `EncodingQuality` + `EncodingOutcome`

**Files:**
- Create: `src/EncodingNormalizer/EncodingQuality.php`
- Create: `src/EncodingNormalizer/EncodingOutcome.php`
- Test: `tests/EncodingNormalizer/EncodingOutcomeTest.php`

**Interfaces:**
- Consumes:
  - `EncodingError` from Task 1
  - `Utf8Html` is created in Task 3. For this task only, `EncodingOutcome` type-hints `Utf8Html` — tests will fail on `Utf8Html` missing until Task 3 **if** you construct a real `Utf8Html`. To keep this task independently testable, introduce `Utf8Html` as a **minimal stub in this task**: public constructor storing `html`, `sourceUrl`, `sourceEncoding` with no invariant checks. Task 3 replaces that stub with the real invariants and `EncodingSource`.
- Produces:
  - `enum EncodingQuality { case Ok; case Degraded; }`
  - `final readonly class EncodingOutcome` with private constructor and:
    - `public static function ok(Utf8Html $html): self`
    - `public static function degraded(Utf8Html $html, EncodingError $warning, ?\Throwable $previous = null): self`
    - `public function isOk(): bool`
    - `public function isDegraded(): bool`
    - Public properties: `EncodingQuality $quality`, `Utf8Html $html`, `?EncodingError $warning = null`, `?\Throwable $previous = null`
  - Invariants (enforced in the private constructor): `Ok` must not carry a warning; `Degraded` requires a warning.

**Design note:** PHP cannot make `Utf8Html` package-private. Task 3 will add invariant checks on the constructor so a caller still cannot build a non-UTF-8 `Utf8Html`. Production code constructs `Utf8Html` only from `EncodingNormalizer`.

- [ ] **Step 1: Write the failing test**

Create `tests/EncodingNormalizer/EncodingOutcomeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingOutcome;
use Yumo\LogRead\EncodingNormalizer\EncodingQuality;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class EncodingOutcomeTest extends TestCase
{
    public function test_ok_factory_has_no_warning(): void
    {
        $html = $this->html();
        $outcome = EncodingOutcome::ok($html);

        self::assertSame(EncodingQuality::Ok, $outcome->quality);
        self::assertTrue($outcome->isOk());
        self::assertFalse($outcome->isDegraded());
        self::assertSame($html, $outcome->html);
        self::assertNull($outcome->warning);
        self::assertNull($outcome->previous);
    }

    public function test_degraded_factory_requires_warning_and_keeps_previous(): void
    {
        $html = $this->html();
        $previous = new \RuntimeException('mb refused');
        $outcome = EncodingOutcome::degraded($html, EncodingError::Conversion, $previous);

        self::assertSame(EncodingQuality::Degraded, $outcome->quality);
        self::assertTrue($outcome->isDegraded());
        self::assertFalse($outcome->isOk());
        self::assertSame($html, $outcome->html);
        self::assertSame(EncodingError::Conversion, $outcome->warning);
        self::assertSame($previous, $outcome->previous);
    }

    public function test_degraded_previous_is_optional(): void
    {
        $outcome = EncodingOutcome::degraded($this->html(), EncodingError::Undeclared);
        self::assertNull($outcome->previous);
        self::assertSame(EncodingError::Undeclared, $outcome->warning);
    }

    private function html(): Utf8Html
    {
        return new Utf8Html(
            html: '<p>ok</p>',
            sourceUrl: 'https://example.com/article',
            sourceEncoding: 'UTF-8',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter EncodingOutcomeTest`

Expected: FAIL with class not found (`EncodingOutcome`, `EncodingQuality`, or `Utf8Html`).

- [ ] **Step 3: Write minimal implementation**

Create `src/EncodingNormalizer/EncodingQuality.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * How callers should treat an `EncodingOutcome`.
 *
 * `Ok` means a trusted encoding was found and conversion succeeded cleanly.
 * `Degraded` still carries UTF-8 HTML, but conversion was lossy or the encoding was only guessed.
 */
enum EncodingQuality
{
    case Ok;
    case Degraded;
}
```

Create `src/EncodingNormalizer/Utf8Html.php` (stub; Task 3 adds `EncodingSource` and invariant checks):

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * UTF-8 HTML taken from one fetched page, plus the URL that page was requested as.
 *
 * `$html` is the document body to parse. `$sourceUrl` is copied from the fetch request
 * URI so later steps can resolve relative links against the real page address.
 */
final readonly class Utf8Html
{
    public function __construct(
        /** HTML bytes that are valid UTF-8 and do not start with a UTF-8 BOM. */
        public string $html,
        /** Request URI this HTML was fetched from; used as the base for relative URLs. */
        public string $sourceUrl,
        /** Encoding name that was used or assumed before conversion to UTF-8. */
        public string $sourceEncoding,
    ) {
    }
}
```

Create `src/EncodingNormalizer/EncodingOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Result of turning fetched HTML bytes into UTF-8.
 *
 * Every outcome includes `Utf8Html`. `quality` says whether conversion was clean (`Ok`)
 * or best-effort (`Degraded`). A degraded outcome always carries an `EncodingError` warning
 * so a caller can log it and continue.
 *
 * `$outcome = EncodingOutcome::ok($html);`
 */
final readonly class EncodingOutcome
{
    private function __construct(
        /** Whether conversion was clean or best-effort. */
        public EncodingQuality $quality,
        /** UTF-8 HTML produced for this page. */
        public Utf8Html $html,
        /** Why the result is degraded; always `null` on `Ok`. */
        public ?EncodingError $warning = null,
        /** Underlying converter exception when conversion was repaired or is being explained. */
        public ?\Throwable $previous = null,
    ) {
        if ($quality === EncodingQuality::Ok && $warning !== null) {
            throw new \InvalidArgumentException('Ok outcome must not carry a warning');
        }
        if ($quality === EncodingQuality::Degraded && $warning === null) {
            throw new \InvalidArgumentException('Degraded outcome requires a warning');
        }
    }

    /**
     * Builds a clean conversion result with no warning.
     */
    public static function ok(Utf8Html $html): self
    {
        return new self(EncodingQuality::Ok, $html);
    }

    /**
     * Builds a best-effort conversion result. `$warning` is required.
     */
    public static function degraded(
        Utf8Html $html,
        EncodingError $warning,
        ?\Throwable $previous = null,
    ): self {
        return new self(EncodingQuality::Degraded, $html, $warning, $previous);
    }

    /**
     * Returns whether conversion was clean.
     */
    public function isOk(): bool
    {
        return $this->quality === EncodingQuality::Ok;
    }

    /**
     * Returns whether conversion was best-effort.
     */
    public function isDegraded(): bool
    {
        return $this->quality === EncodingQuality::Degraded;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter EncodingOutcomeTest`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/EncodingNormalizer/EncodingQuality.php src/EncodingNormalizer/EncodingOutcome.php src/EncodingNormalizer/Utf8Html.php tests/EncodingNormalizer/EncodingOutcomeTest.php
git commit -m "$(cat <<'EOF'
feat(encoding-normalizer): add Ok/Degraded outcome around Utf8Html

EOF
)"
```

---

### Task 3: `EncodingSource` + `Utf8Html` invariants

**Files:**
- Create: `src/EncodingNormalizer/EncodingSource.php`
- Modify: `src/EncodingNormalizer/Utf8Html.php` (replace the Task 2 stub)
- Modify: `tests/EncodingNormalizer/EncodingOutcomeTest.php` (pass `EncodingSource` into `Utf8Html`)
- Test: `tests/EncodingNormalizer/Utf8HtmlTest.php`

**Interfaces:**
- Consumes: `Utf8Html` stub from Task 2
- Produces:
  - `enum EncodingSource { case Bom; case HttpHeader; case Meta; case Utf8Default; case Detect; case Lossy; }`
  - `final readonly class Utf8Html { public function __construct(public string $html, public string $sourceUrl, public string $sourceEncoding, public EncodingSource $source) }`
  - Constructor throws `\InvalidArgumentException` when:
    - `$html` is not valid UTF-8 (`mb_check_encoding($html, 'UTF-8')` is false)
    - `$html` starts with the UTF-8 BOM bytes `EF BB BF`
    - `trim($sourceUrl) === ''`
    - `trim($sourceEncoding) === ''`
  - Empty `$html` is allowed (valid UTF-8, no BOM).

- [ ] **Step 1: Write the failing test**

Create `tests/EncodingNormalizer/Utf8HtmlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class Utf8HtmlTest extends TestCase
{
    public function test_stores_all_fields(): void
    {
        $html = new Utf8Html(
            html: '<html></html>',
            sourceUrl: 'https://example.com/article',
            sourceEncoding: 'ISO-8859-1',
            source: EncodingSource::HttpHeader,
        );

        self::assertSame('<html></html>', $html->html);
        self::assertSame('https://example.com/article', $html->sourceUrl);
        self::assertSame('ISO-8859-1', $html->sourceEncoding);
        self::assertSame(EncodingSource::HttpHeader, $html->source);
    }

    public function test_empty_html_is_allowed(): void
    {
        $html = new Utf8Html(
            html: '',
            sourceUrl: 'https://example.com/',
            sourceEncoding: 'UTF-8',
            source: EncodingSource::Utf8Default,
        );

        self::assertSame('', $html->html);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_invalid_values(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): Utf8Html, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'invalid utf-8' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: "\xC3\x28",
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'html must be valid UTF-8',
            ],
            'leading utf-8 bom' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: "\xEF\xBB\xBF<html></html>",
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::Bom,
                ),
                'html must not start with a UTF-8 BOM',
            ],
            'empty sourceUrl' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: '',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceUrl must not be empty',
            ],
            'blank sourceUrl' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: '   ',
                    sourceEncoding: 'UTF-8',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceUrl must not be empty',
            ],
            'empty sourceEncoding' => [
                static fn (): Utf8Html => new Utf8Html(
                    html: '<p></p>',
                    sourceUrl: 'https://example.com/',
                    sourceEncoding: '',
                    source: EncodingSource::HttpHeader,
                ),
                'sourceEncoding must not be empty',
            ],
        ];
    }
}
```

Update `tests/EncodingNormalizer/EncodingOutcomeTest.php` private helper to pass `source`. Add `use Yumo\LogRead\EncodingNormalizer\EncodingSource;` to that test file and replace `html()` with:

```php
    private function html(): Utf8Html
    {
        return new Utf8Html(
            html: '<p>ok</p>',
            sourceUrl: 'https://example.com/article',
            sourceEncoding: 'UTF-8',
            source: EncodingSource::HttpHeader,
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter 'EncodingNormalizer\\'`

Expected: FAIL — `Utf8HtmlTest` fails (`EncodingSource` not found, and/or constructor argument count / missing invariant exceptions). `EncodingOutcomeTest` also fails until the helper passes `source`.

- [ ] **Step 3: Write minimal implementation**

Create `src/EncodingNormalizer/EncodingSource.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Where `Utf8Html::$sourceEncoding` was taken from.
 *
 * This is a diagnostic for logs and tests. Callers must not re-run charset detection
 * from this value; they already have UTF-8 HTML on the same object.
 */
enum EncodingSource
{
    /** A byte-order mark at the start of the raw body. */
    case Bom;

    /** `charset` parameter on the HTTP `Content-Type` header. */
    case HttpHeader;

    /** HTML `<meta charset>` or `http-equiv="content-type"` in the first 1024 bytes. */
    case Meta;

    /** No declaration; the raw bytes were already valid UTF-8. */
    case Utf8Default;

    /** `mb_detect_encoding` guess among a small candidate list. Always a weak signal. */
    case Detect;

    /** No trusted encoding; bytes were repaired into UTF-8 with substitution. */
    case Lossy;
}
```

Replace `src/EncodingNormalizer/Utf8Html.php` with:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * UTF-8 HTML taken from one fetched page, plus the URL that page was requested as.
 *
 * `$html` is valid UTF-8 and does not start with a UTF-8 BOM. Degraded conversion may
 * still contain replacement characters or mojibake, but the bytes are UTF-8.
 * `$sourceUrl` is copied from the fetch request URI so later steps can resolve relative
 * links against the real page address.
 *
 * Production code constructs this type from `EncodingNormalizer`. Tests may construct
 * one directly when the UTF-8 invariants already hold.
 */
final readonly class Utf8Html
{
    public function __construct(
        /** HTML that is valid UTF-8 and does not start with a UTF-8 BOM. */
        public string $html,
        /** Request URI this HTML was fetched from; used as the base for relative URLs. */
        public string $sourceUrl,
        /** Encoding name that was used or assumed before conversion to UTF-8. */
        public string $sourceEncoding,
        /** Where `$sourceEncoding` was taken from. */
        public EncodingSource $source,
    ) {
        if (!mb_check_encoding($html, 'UTF-8')) {
            throw new \InvalidArgumentException('html must be valid UTF-8');
        }
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            throw new \InvalidArgumentException('html must not start with a UTF-8 BOM');
        }
        if (trim($sourceUrl) === '') {
            throw new \InvalidArgumentException('sourceUrl must not be empty');
        }
        if (trim($sourceEncoding) === '') {
            throw new \InvalidArgumentException('sourceEncoding must not be empty');
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter 'EncodingNormalizer\\'`

Expected: PASS (`Utf8HtmlTest` 7 tests + previous EncodingNormalizer tests).

- [ ] **Step 5: Commit**

```bash
git add src/EncodingNormalizer/EncodingSource.php src/EncodingNormalizer/Utf8Html.php tests/EncodingNormalizer/Utf8HtmlTest.php tests/EncodingNormalizer/EncodingOutcomeTest.php
git commit -m "$(cat <<'EOF'
feat(encoding-normalizer): enforce Utf8Html UTF-8 and provenance invariants

EOF
)"
```

---

### Task 4: `Utf8Converter` + Ok cascade

**Files:**
- Modify: `composer.json` / `composer.lock` (direct `fossar/transcoder` `^3.0`)
- Create: `src/EncodingNormalizer/Utf8Converter.php`
- Create: `src/EncodingNormalizer/TranscoderUtf8Converter.php`
- Create: `src/EncodingNormalizer/EncodingNormalizer.php`
- Test: `tests/EncodingNormalizer/EncodingNormalizerTest.php`

**Interfaces:**
- Consumes: `FetchedPage` (`Yumo\LogRead\HttpFetcher\FetchedPage`), Task 1–3 types
- Produces:
  - `interface Utf8Converter { public function convert(string $bytes, string $fromEncoding): string; }`
  - `final class TranscoderUtf8Converter implements Utf8Converter` wrapping `Ddeboer\Transcoder\Transcoder::create()`
  - `final class EncodingNormalizer { public function __construct(?Utf8Converter $converter = null); public function normalize(FetchedPage $page): EncodingOutcome; }`
  - Detection order for **Ok** paths in this task: UTF-8 BOM → HTTP charset → meta (first 1024 bytes) → valid UTF-8 default. Non-UTF-8 BOM, detect-only, and lossy repair are Task 5.
  - `sourceUrl` is always `$page->requestUri`.
  - Declared UTF-8 that is already valid is returned as-is (after stripping a UTF-8 BOM), without requiring a round-trip through the transcoder.
  - Unknown charset / invalid sequences still **throw** `EncodingNormalizerException` in this task (Task 5 turns those into `Degraded`).

**Vendor notes (verified against installed packages):**
- `fossar/guzzle-transcoder` 0.3.x depends on `fossar/transcoder`, which autoloads `Ddeboer\Transcoder\` and `replace`s `ddeboer/transcoder`. Require `fossar/transcoder` directly because we import that namespace.
- `ContentTypeExtractor::getContentTypeFromHeader(array $headers, string $targetEncoding): ?array{string, ?string, array<string, ?string>}` — use index `1` as the charset. Pass `$targetEncoding = 'utf-8'` only because the helper requires it; ignore the rewritten parameter map.
- `ContentTypeExtractor::getContentTypeFromHtml(string $content, string $targetEncoding): array{?string, array<string, string>}` — use index `0` as the charset. Call it on `substr($bytes, 0, 1024)` only.
- Do **not** instantiate `GuzzleTranscoder`.

- [ ] **Step 1: Write the failing test**

Create `tests/EncodingNormalizer/EncodingNormalizerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\EncodingNormalizer\EncodingOutcome;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\HttpFetcher\FetchedPage;

final class EncodingNormalizerTest extends TestCase
{
    public function test_header_utf8_ascii_body_is_ok(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertSame('utf-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }

    public function test_quoted_and_case_insensitive_header_charset(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; CHARSET="UTF-8"');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
    }

    public function test_header_iso_8859_1_latin1_bytes_become_utf8_e_acute(): void
    {
        $page = $this->page("<html>caf\xE9</html>", 'text/html; charset=ISO-8859-1');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertSame('ISO-8859-1', $outcome->html->sourceEncoding);
        self::assertSame('<html>café</html>', $outcome->html->html);
    }

    public function test_meta_charset_only(): void
    {
        $body = "<html><head><meta charset=\"windows-1252\"></head><body>caf\xE9</body></html>";
        $page = $this->page($body, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Meta, $outcome->html->source);
        self::assertSame('windows-1252', $outcome->html->sourceEncoding);
        self::assertStringContainsString('café', $outcome->html->html);
        self::assertStringContainsString('charset="windows-1252"', $outcome->html->html);
    }

    public function test_html4_http_equiv_meta_charset(): void
    {
        $body = '<html><head><meta http-equiv="content-type" content="text/html; charset=ISO-8859-1"></head>'
            . "<body>caf\xE9</body></html>";
        $page = $this->page($body, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Meta, $outcome->html->source);
        self::assertStringContainsString('café', $outcome->html->html);
    }

    public function test_http_charset_wins_over_conflicting_meta(): void
    {
        $body = "<html><head><meta charset=\"ISO-8859-1\"></head><body>caf\xC3\xA9</body></html>";
        $page = $this->page($body, 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertStringContainsString('café', $outcome->html->html);
        self::assertStringNotContainsString('Ã', $outcome->html->html);
    }

    public function test_utf8_bom_is_stripped_and_wins_over_http_charset(): void
    {
        $page = $this->page("\xEF\xBB\xBF<html>ok</html>", 'text/html; charset=ISO-8859-1');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Bom, $outcome->html->source);
        self::assertSame('UTF-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }

    public function test_no_declaration_valid_utf8_uses_default(): void
    {
        $page = $this->page('<html>café</html>', 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame(EncodingSource::Utf8Default, $outcome->html->source);
        self::assertSame('UTF-8', $outcome->html->sourceEncoding);
        self::assertSame('<html>café</html>', $outcome->html->html);
    }

    public function test_empty_body_with_utf8_header_is_ok(): void
    {
        $page = $this->page('', 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertOkUtf8($outcome, $page);
        self::assertSame('', $outcome->html->html);
    }

    public function test_source_url_comes_from_request_uri(): void
    {
        $page = $this->page(
            '<html>ok</html>',
            'text/html; charset=utf-8',
            'https://example.net/posts/1?q=1',
        );
        $outcome = (new EncodingNormalizer())->normalize($page);

        self::assertSame('https://example.net/posts/1?q=1', $outcome->html->sourceUrl);
    }

    /**
     * Builds a successful fetch fixture. Status is unused by the normalizer and stays 200.
     */
    private function page(
        string $body,
        string $contentType,
        string $requestUri = 'https://example.com/article',
    ): FetchedPage {
        return new FetchedPage($body, $contentType, 200, $requestUri);
    }

    /**
     * Asserts the outcome is clean UTF-8 HTML for `$page`, with provenance copied from the fetch.
     */
    private function assertOkUtf8(EncodingOutcome $outcome, FetchedPage $page): void
    {
        self::assertTrue($outcome->isOk());
        self::assertNull($outcome->warning);
        self::assertTrue(mb_check_encoding($outcome->html->html, 'UTF-8'));
        self::assertSame($page->requestUri, $outcome->html->sourceUrl);
        self::assertFalse(str_starts_with($outcome->html->html, "\xEF\xBB\xBF"));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter EncodingNormalizerTest`

Expected: FAIL with class not found (`EncodingNormalizer`).

- [ ] **Step 3: Add the direct Transcoder dependency and write the implementation**

Run:

```bash
composer require fossar/transcoder:^3.0
```

Expected: `composer.json` lists `fossar/transcoder` under `require`; lock file stays on `^3.0` (already installed transitively).

Create `src/EncodingNormalizer/Utf8Converter.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;

/**
 * Converts raw HTML bytes from a named encoding into UTF-8.
 *
 * Production uses `TranscoderUtf8Converter`. Tests inject a fake to force conversion
 * failures without depending on mbstring’s substitution behaviour.
 */
interface Utf8Converter
{
    /**
     * Converts `$bytes` from `$fromEncoding` into UTF-8.
     *
     * @throws UnsupportedEncodingException When `$fromEncoding` is not available on this platform
     * @throws \Throwable When conversion fails for any other reason
     */
    public function convert(string $bytes, string $fromEncoding): string;
}
```

Create `src/EncodingNormalizer/TranscoderUtf8Converter.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Transcoder;
use Ddeboer\Transcoder\TranscoderInterface;

/**
 * Converts encodings with `Ddeboer\Transcoder\Transcoder` (mbstring, then iconv).
 */
final class TranscoderUtf8Converter implements Utf8Converter
{
    /** Vendor transcoder that maps `$fromEncoding` to UTF-8. */
    private TranscoderInterface $transcoder;

    /**
     * Creates the adapter. When `$transcoder` is omitted, `Transcoder::create()` is used.
     */
    public function __construct(?TranscoderInterface $transcoder = null)
    {
        $this->transcoder = $transcoder ?? Transcoder::create();
    }

    public function convert(string $bytes, string $fromEncoding): string
    {
        return $this->transcoder->transcode($bytes, $fromEncoding, 'UTF-8');
    }
}
```

Create `src/EncodingNormalizer/EncodingNormalizer.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;
use Fossar\GuzzleTranscoder\ContentTypeExtractor;
use Yumo\LogRead\HttpFetcher\FetchedPage;

/**
 * Turns opaque HTML response bytes into a UTF-8 HTML document, or throws.
 *
 * Encoding is taken from a UTF-8 BOM, the HTTP Content-Type charset, a short HTML meta
 * prescan, or a UTF-8 validity check, in that order. An HTTP charset wins over a
 * conflicting meta declaration. The result is tagged Ok or Degraded so callers can log
 * a warning without treating lossy conversion as a hard failure.
 *
 * `$outcome = (new EncodingNormalizer())->normalize($page);`
 *
 * Unit tests may inject a `Utf8Converter` to force conversion failures.
 */
final class EncodingNormalizer
{
    /** How many leading body bytes are scanned for `<meta charset>` / `http-equiv`. */
    private const META_PRESCAN_BYTES = 1024;

    /** Converts named encodings to UTF-8. */
    private Utf8Converter $converter;

    /**
     * Creates the normalizer. When `$converter` is omitted, the production transcoder adapter is used.
     */
    public function __construct(?Utf8Converter $converter = null)
    {
        $this->converter = $converter ?? new TranscoderUtf8Converter();
    }

    /**
     * Converts `$page->body` to UTF-8 HTML and copies `$page->requestUri` onto the result.
     *
     * @throws EncodingNormalizerException When even lossy UTF-8 cannot be produced
     */
    public function normalize(FetchedPage $page): EncodingOutcome
    {
        $bytes = $page->body;
        $detected = $this->detect($bytes, $page->contentType);

        $working = $bytes;
        if ($detected['source'] === EncodingSource::Bom && $this->isUtf8Name($detected['encoding'])) {
            $working = $this->stripUtf8Bom($working);
        }

        try {
            $html = $this->convertStrict($working, $detected['encoding']);
            $html = $this->stripUtf8Bom($html);
            if (!mb_check_encoding($html, 'UTF-8')) {
                throw new EncodingNormalizerException(EncodingError::Conversion);
            }

            return EncodingOutcome::ok(new Utf8Html(
                $html,
                $page->requestUri,
                $detected['encoding'],
                $detected['source'],
            ));
        } catch (EncodingNormalizerException $e) {
            throw $e;
        } catch (UnsupportedEncodingException $e) {
            throw new EncodingNormalizerException(EncodingError::Unsupported, '', $e);
        } catch (\Throwable $e) {
            throw new EncodingNormalizerException(EncodingError::Conversion, '', $e);
        }
    }

    /**
     * @return array{encoding: string, source: EncodingSource}
     */
    private function detect(string $bytes, string $contentType): array
    {
        $bom = $this->detectUtf8Bom($bytes);
        if ($bom !== null) {
            return $bom;
        }

        $httpCharset = $this->parseHttpCharset($contentType);
        if ($httpCharset !== null) {
            return ['encoding' => $httpCharset, 'source' => EncodingSource::HttpHeader];
        }

        $metaCharset = $this->parseMetaCharset($bytes);
        if ($metaCharset !== null) {
            return ['encoding' => $metaCharset, 'source' => EncodingSource::Meta];
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return ['encoding' => 'UTF-8', 'source' => EncodingSource::Utf8Default];
        }

        throw new EncodingNormalizerException(EncodingError::Undeclared);
    }

    /**
     * @return array{encoding: string, source: EncodingSource}|null
     */
    private function detectUtf8Bom(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['encoding' => 'UTF-8', 'source' => EncodingSource::Bom];
        }

        return null;
    }

    private function parseHttpCharset(string $contentType): ?string
    {
        if ($contentType === '') {
            return null;
        }

        $extracted = ContentTypeExtractor::getContentTypeFromHeader(
            ['Content-Type' => $contentType],
            'utf-8',
        );
        if ($extracted === null) {
            return null;
        }

        $charset = $extracted[1];
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function parseMetaCharset(string $bytes): ?string
    {
        $prescan = substr($bytes, 0, self::META_PRESCAN_BYTES);
        [$charset] = ContentTypeExtractor::getContentTypeFromHtml($prescan, 'utf-8');
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function convertStrict(string $bytes, string $fromEncoding): string
    {
        if ($this->isUtf8Name($fromEncoding)) {
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                throw new EncodingNormalizerException(EncodingError::Conversion);
            }

            return $bytes;
        }

        return $this->converter->convert($bytes, $fromEncoding);
    }

    private function stripUtf8Bom(string $html): string
    {
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            return substr($html, 3);
        }

        return $html;
    }

    private function isUtf8Name(string $encoding): bool
    {
        return strtoupper(str_replace(['-', '_'], '', $encoding)) === 'UTF8';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter EncodingNormalizerTest`

Expected: PASS (10 tests). If `test_quoted_and_case_insensitive_header_charset` fails because `ContentTypeExtractor` did not parse `CHARSET="UTF-8"`, keep the test and fix `parseHttpCharset` with a case-insensitive fallback: after `explode(';', $contentType, 2)`, scan parameters for `charset=` yourself. Do not drop the test.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock src/EncodingNormalizer/Utf8Converter.php src/EncodingNormalizer/TranscoderUtf8Converter.php src/EncodingNormalizer/EncodingNormalizer.php tests/EncodingNormalizer/EncodingNormalizerTest.php
git commit -m "$(cat <<'EOF'
feat(encoding-normalizer): convert declared encodings to UTF-8 with HTTP-over-meta cascade

EOF
)"
```

---

### Task 5: Degraded cascade (lossy / best-effort)

**Files:**
- Modify: `src/EncodingNormalizer/Utf8Converter.php` (add `convertLossy`)
- Modify: `src/EncodingNormalizer/TranscoderUtf8Converter.php`
- Modify: `src/EncodingNormalizer/EncodingNormalizer.php`
- Modify: `tests/EncodingNormalizer/EncodingNormalizerTest.php`

**Interfaces:**
- Consumes: Task 4 `EncodingNormalizer::normalize(FetchedPage $page): EncodingOutcome` (still throws on unknown charset / invalid UTF-8 / undeclared binary)
- Produces: the same method, but:
  - After a failed strict convert, or when encoding is only guessed / undeclared, call `Utf8Converter::convertLossy(string $bytes, string $fromEncoding): string`
  - If lossy yields valid UTF-8 → `EncodingOutcome::degraded($html, $warning, $previous)`
  - `EncodingSource::Detect` when `mb_detect_encoding` matches `['UTF-8', 'Windows-1252', 'ISO-8859-1']` with `$strict = true`; warning `EncodingError::Undeclared`
  - `EncodingSource::Lossy` when even detect fails; still warning `Undeclared`; lossy assumes UTF-8 with `mb_scrub`
  - Non-UTF-8 BOM (`UTF-16LE` `FF FE`, `UTF-16BE` `FE FF`, `UTF-32LE` `FF FE 00 00`, `UTF-32BE` `00 00 FE FF`) → always `Degraded` + `EncodingError::Unsupported` after conversion (check 4-byte BOMs before 2-byte)
  - Unknown charset name (`charset=x-unknown`) → `Degraded` + `Unsupported` (lossy `mb_scrub` of the raw bytes)
  - Invalid sequences under declared UTF-8 → `Degraded` + `Conversion` after `mb_scrub`
  - Meta charset that starts after byte 1024 must **not** be used

**Verified fixture (PHP 8.5.10 / ext-mbstring):**
- `"\x80\x81\x82\x83 not utf8 \xFF"` is not valid UTF-8; `mb_detect_encoding(..., ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)` returns `'Windows-1252'`. Assert `EncodingSource::Detect` and `sourceEncoding === 'Windows-1252'` for that exact body — do not recompute the guess in the test.
- `mb_scrub` may replace invalid sequences with `?` rather than U+FFFD. Assert `mb_check_encoding(..., 'UTF-8')` and quality/warning, **not** a specific replacement character.
- UTF-16LE with BOM `FF FE` + UTF-16LE payload of `<html>x</html>` converts to UTF-8 with a leading U+FEFF (`EF BB BF`); `stripUtf8Bom` must remove it so `$html` is `<html>x</html>`.
- `EncodingSource::Lossy` is the fallback when `mb_detect_encoding` returns false. On this PHP build, Windows-1252 matches arbitrary 8-bit bytes, so do **not** add a fixture that asserts `Lossy`; keep the branch for when detect returns false.

- [ ] **Step 1: Write the failing tests**

Append to `tests/EncodingNormalizer/EncodingNormalizerTest.php` (keep the Task 4 tests; add the import for `EncodingError`):

```php
use Yumo\LogRead\EncodingNormalizer\EncodingError;
```

```php
    public function test_undeclared_invalid_utf8_is_degraded_via_detect(): void
    {
        $page = $this->page("\x80\x81\x82\x83 not utf8 \xFF", 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertDegradedUtf8($outcome, $page, EncodingError::Undeclared);
        self::assertSame(EncodingSource::Detect, $outcome->html->source);
        self::assertSame('Windows-1252', $outcome->html->sourceEncoding);
    }

    public function test_unknown_charset_name_is_degraded_unsupported(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; charset=x-unknown');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertDegradedUtf8($outcome, $page, EncodingError::Unsupported);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertSame('x-unknown', $outcome->html->sourceEncoding);
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }

    public function test_invalid_sequences_under_declared_utf8_are_degraded_conversion(): void
    {
        $page = $this->page("<html>\xC3\x28</html>", 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertDegradedUtf8($outcome, $page, EncodingError::Conversion);
        self::assertSame(EncodingSource::HttpHeader, $outcome->html->source);
        self::assertStringStartsWith('<html>', $outcome->html->html);
        self::assertStringEndsWith('</html>', $outcome->html->html);
    }

    public function test_utf16le_bom_is_degraded_unsupported(): void
    {
        $payload = mb_convert_encoding('<html>x</html>', 'UTF-16LE', 'UTF-8');
        $page = $this->page("\xFF\xFE" . $payload, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        $this->assertDegradedUtf8($outcome, $page, EncodingError::Unsupported);
        self::assertSame(EncodingSource::Bom, $outcome->html->source);
        self::assertSame('UTF-16LE', $outcome->html->sourceEncoding);
        self::assertSame('<html>x</html>', $outcome->html->html);
    }

    public function test_meta_beyond_prescan_window_is_not_used(): void
    {
        $body = str_repeat(' ', 1024)
            . "<meta charset=\"ISO-8859-1\"><html>caf\xE9</html>";
        $page = $this->page($body, 'text/html');
        $outcome = (new EncodingNormalizer())->normalize($page);

        self::assertFalse($outcome->isOk());
        self::assertNotSame(EncodingSource::Meta, $outcome->html->source);
        $this->assertDegradedUtf8($outcome, $page, EncodingError::Undeclared);
    }

    /**
     * Asserts the outcome is valid UTF-8 HTML with the given warning, provenance copied from the fetch.
     */
    private function assertDegradedUtf8(
        EncodingOutcome $outcome,
        FetchedPage $page,
        EncodingError $warning,
    ): void {
        self::assertTrue($outcome->isDegraded());
        self::assertSame($warning, $outcome->warning);
        self::assertTrue(mb_check_encoding($outcome->html->html, 'UTF-8'));
        self::assertSame($page->requestUri, $outcome->html->sourceUrl);
        self::assertFalse(str_starts_with($outcome->html->html, "\xEF\xBB\xBF"));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter EncodingNormalizerTest`

Expected: FAIL — the new cases currently **throw** `EncodingNormalizerException` (`Undeclared` / `Unsupported` / `Conversion`) instead of returning `Degraded`.

- [ ] **Step 3: Add lossy conversion and the rest of the cascade**

Replace `src/EncodingNormalizer/Utf8Converter.php` with:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;

/**
 * Converts raw HTML bytes from a named encoding into UTF-8.
 *
 * Production uses `TranscoderUtf8Converter`. Tests inject a fake to force conversion
 * failures without depending on mbstring’s substitution behaviour.
 */
interface Utf8Converter
{
    /**
     * Converts `$bytes` from `$fromEncoding` into UTF-8.
     *
     * @throws UnsupportedEncodingException When `$fromEncoding` is not available on this platform
     * @throws \Throwable When conversion fails for any other reason
     */
    public function convert(string $bytes, string $fromEncoding): string;

    /**
     * Converts `$bytes` into UTF-8 with substitution or ignore so the result is valid UTF-8 when possible.
     *
     * `$fromEncoding` is used when it is a known mbstring encoding; otherwise the bytes are
     * treated as UTF-8 and invalid sequences are replaced.
     *
     * @throws \Throwable When even a lossy conversion cannot produce UTF-8
     */
    public function convertLossy(string $bytes, string $fromEncoding): string;
}
```

Replace `src/EncodingNormalizer/TranscoderUtf8Converter.php` with:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Transcoder;
use Ddeboer\Transcoder\TranscoderInterface;

/**
 * Converts encodings with `Ddeboer\Transcoder\Transcoder` (mbstring, then iconv).
 *
 * Lossy conversion uses `mb_convert_encoding` when `$fromEncoding` is a known mbstring
 * encoding, and `mb_scrub` otherwise, so invalid sequences become replacement characters
 * instead of aborting.
 */
final class TranscoderUtf8Converter implements Utf8Converter
{
    /** Vendor transcoder that maps `$fromEncoding` to UTF-8. */
    private TranscoderInterface $transcoder;

    /**
     * Creates the adapter. When `$transcoder` is omitted, `Transcoder::create()` is used.
     */
    public function __construct(?TranscoderInterface $transcoder = null)
    {
        $this->transcoder = $transcoder ?? Transcoder::create();
    }

    public function convert(string $bytes, string $fromEncoding): string
    {
        return $this->transcoder->transcode($bytes, $fromEncoding, 'UTF-8');
    }

    public function convertLossy(string $bytes, string $fromEncoding): string
    {
        if ($this->isSupportedMbEncoding($fromEncoding) && !$this->isUtf8Name($fromEncoding)) {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $fromEncoding);
            if (!is_string($converted)) {
                throw new \RuntimeException('mb_convert_encoding did not return a string');
            }

            return mb_scrub($converted, 'UTF-8');
        }

        return mb_scrub($bytes, 'UTF-8');
    }

    private function isUtf8Name(string $encoding): bool
    {
        return strtoupper(str_replace(['-', '_'], '', $encoding)) === 'UTF8';
    }

    private function isSupportedMbEncoding(string $encoding): bool
    {
        foreach (mb_list_encodings() as $name) {
            if (strcasecmp($name, $encoding) === 0) {
                return true;
            }
        }

        return false;
    }
}
```

Replace `src/EncodingNormalizer/EncodingNormalizer.php` with:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;
use Fossar\GuzzleTranscoder\ContentTypeExtractor;
use Yumo\LogRead\HttpFetcher\FetchedPage;

/**
 * Turns opaque HTML response bytes into a UTF-8 HTML document, or throws.
 *
 * Encoding is taken from a BOM, the HTTP Content-Type charset, a short HTML meta
 * prescan, a UTF-8 validity check, then an optional `mb_detect_encoding` guess.
 * An HTTP charset wins over a conflicting meta declaration. The result is tagged
 * Ok or Degraded so callers can log a warning without treating lossy conversion
 * as a hard failure.
 *
 * `$outcome = (new EncodingNormalizer())->normalize($page);`
 *
 * Unit tests may inject a `Utf8Converter` to force conversion failures.
 */
final class EncodingNormalizer
{
    /** How many leading body bytes are scanned for `<meta charset>` / `http-equiv`. */
    private const META_PRESCAN_BYTES = 1024;

    /**
     * Candidate list for `mb_detect_encoding` when nothing was declared.
     *
     * @var list<string>
     */
    private const DETECT_CANDIDATES = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    /** Converts named encodings to UTF-8, including a lossy fallback. */
    private Utf8Converter $converter;

    /**
     * Creates the normalizer. When `$converter` is omitted, the production transcoder adapter is used.
     */
    public function __construct(?Utf8Converter $converter = null)
    {
        $this->converter = $converter ?? new TranscoderUtf8Converter();
    }

    /**
     * Converts `$page->body` to UTF-8 HTML and copies `$page->requestUri` onto the result.
     *
     * @throws EncodingNormalizerException When even lossy UTF-8 cannot be produced
     */
    public function normalize(FetchedPage $page): EncodingOutcome
    {
        $bytes = $page->body;
        $detected = $this->detect($bytes, $page->contentType);

        $working = $bytes;
        if ($detected['source'] === EncodingSource::Bom && $this->isUtf8Name($detected['encoding'])) {
            $working = $this->stripUtf8Bom($working);
        }

        $warning = $detected['warning'];
        $previous = null;

        if (!$detected['weak']) {
            try {
                $html = $this->convertStrict($working, $detected['encoding']);
                $html = $this->stripUtf8Bom($html);
                if (!mb_check_encoding($html, 'UTF-8')) {
                    throw new EncodingNormalizerException(EncodingError::Conversion);
                }

                return EncodingOutcome::ok(new Utf8Html(
                    $html,
                    $page->requestUri,
                    $detected['encoding'],
                    $detected['source'],
                ));
            } catch (EncodingNormalizerException $e) {
                $warning = $e->error;
                $previous = $e->getPrevious();
            } catch (UnsupportedEncodingException $e) {
                $warning = EncodingError::Unsupported;
                $previous = $e;
            } catch (\Throwable $e) {
                $warning = EncodingError::Conversion;
                $previous = $e;
            }
        }

        return $this->degradedFromLossy(
            $working,
            $page->requestUri,
            $detected['encoding'],
            $detected['source'],
            $warning ?? EncodingError::Conversion,
            $previous,
        );
    }

    /**
     * @return array{encoding: string, source: EncodingSource, weak: bool, warning: ?EncodingError}
     */
    private function detect(string $bytes, string $contentType): array
    {
        $bom = $this->detectBom($bytes);
        if ($bom !== null) {
            $weak = !$this->isUtf8Name($bom['encoding']);

            return [
                'encoding' => $bom['encoding'],
                'source' => EncodingSource::Bom,
                'weak' => $weak,
                'warning' => $weak ? EncodingError::Unsupported : null,
            ];
        }

        $httpCharset = $this->parseHttpCharset($contentType);
        if ($httpCharset !== null) {
            return [
                'encoding' => $httpCharset,
                'source' => EncodingSource::HttpHeader,
                'weak' => false,
                'warning' => null,
            ];
        }

        $metaCharset = $this->parseMetaCharset($bytes);
        if ($metaCharset !== null) {
            return [
                'encoding' => $metaCharset,
                'source' => EncodingSource::Meta,
                'weak' => false,
                'warning' => null,
            ];
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [
                'encoding' => 'UTF-8',
                'source' => EncodingSource::Utf8Default,
                'weak' => false,
                'warning' => null,
            ];
        }

        $guessed = mb_detect_encoding($bytes, self::DETECT_CANDIDATES, true);
        if (is_string($guessed) && $guessed !== '') {
            return [
                'encoding' => $guessed,
                'source' => EncodingSource::Detect,
                'weak' => true,
                'warning' => EncodingError::Undeclared,
            ];
        }

        return [
            'encoding' => 'UTF-8',
            'source' => EncodingSource::Lossy,
            'weak' => true,
            'warning' => EncodingError::Undeclared,
        ];
    }

    /**
     * @return array{encoding: string}|null
     */
    private function detectBom(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\x00\x00\xFE\xFF")) {
            return ['encoding' => 'UTF-32BE'];
        }
        if (str_starts_with($bytes, "\xFF\xFE\x00\x00")) {
            return ['encoding' => 'UTF-32LE'];
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['encoding' => 'UTF-8'];
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return ['encoding' => 'UTF-16BE'];
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return ['encoding' => 'UTF-16LE'];
        }

        return null;
    }

    private function parseHttpCharset(string $contentType): ?string
    {
        if ($contentType === '') {
            return null;
        }

        $extracted = ContentTypeExtractor::getContentTypeFromHeader(
            ['Content-Type' => $contentType],
            'utf-8',
        );
        if ($extracted === null) {
            return null;
        }

        $charset = $extracted[1];
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function parseMetaCharset(string $bytes): ?string
    {
        $prescan = substr($bytes, 0, self::META_PRESCAN_BYTES);
        [$charset] = ContentTypeExtractor::getContentTypeFromHtml($prescan, 'utf-8');
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function convertStrict(string $bytes, string $fromEncoding): string
    {
        if ($this->isUtf8Name($fromEncoding)) {
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                throw new EncodingNormalizerException(EncodingError::Conversion);
            }

            return $bytes;
        }

        return $this->converter->convert($bytes, $fromEncoding);
    }

    private function degradedFromLossy(
        string $bytes,
        string $sourceUrl,
        string $sourceEncoding,
        EncodingSource $source,
        EncodingError $warning,
        ?\Throwable $previous,
    ): EncodingOutcome {
        try {
            $html = $this->converter->convertLossy($bytes, $sourceEncoding);
            $html = $this->stripUtf8Bom($html);
            if (!mb_check_encoding($html, 'UTF-8')) {
                throw new EncodingNormalizerException($warning, '', $previous);
            }

            return EncodingOutcome::degraded(
                new Utf8Html($html, $sourceUrl, $sourceEncoding, $source),
                $warning,
                $previous,
            );
        } catch (EncodingNormalizerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncodingNormalizerException($warning, '', $e);
        }
    }

    private function stripUtf8Bom(string $html): string
    {
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            return substr($html, 3);
        }

        return $html;
    }

    private function isUtf8Name(string $encoding): bool
    {
        return strtoupper(str_replace(['-', '_'], '', $encoding)) === 'UTF8';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter EncodingNormalizerTest`

Expected: PASS (Task 4’s 10 Ok tests plus 5 Degraded tests). All returned outcomes must still satisfy `mb_check_encoding($outcome->html->html, 'UTF-8')` and `sourceUrl === requestUri`.

If `test_utf16le_bom_is_degraded_unsupported` fails because `mb_convert_encoding` of the test fixture already includes a BOM, build the payload without a BOM (the hex of UTF-16LE `<html>x</html>` without BOM starts `3c006800…`) and prefix exactly `"\xFF\xFE"`.

If `test_meta_beyond_prescan_window_is_not_used` is `Ok` with `Utf8Default`, the latin1 `0xE9` is missing from the fixture — keep `caf\xE9` after the 1024-space prefix.

- [ ] **Step 5: Commit**

```bash
git add src/EncodingNormalizer/Utf8Converter.php src/EncodingNormalizer/TranscoderUtf8Converter.php src/EncodingNormalizer/EncodingNormalizer.php tests/EncodingNormalizer/EncodingNormalizerTest.php
git commit -m "$(cat <<'EOF'
feat(encoding-normalizer): return Degraded Utf8Html for lossy and undeclared encodings

EOF
)"
```

---

### Task 6: Hard failure + exit criteria

**Files:**
- Create: `tests/EncodingNormalizer/FakeUtf8Converter.php`
- Modify: `tests/EncodingNormalizer/EncodingNormalizerTest.php`
- Test: full suite + quality gates

**Interfaces:**
- Consumes: `Utf8Converter::convert` / `convertLossy` and `EncodingNormalizer::__construct(?Utf8Converter $converter = null)` from Task 5
- Produces:
  - `FakeUtf8Converter` that always throws on `convert`, and either throws or returns a given string on `convertLossy`
  - `normalize()` throws `EncodingNormalizerException` with `EncodingError::Conversion` when lossy conversion throws or returns non-UTF-8
  - Confirmation that Phase 3 exit criteria hold

**Hard-fail fixtures must use a non-UTF-8 declared charset** (for example `charset=ISO-8859-1`). Declared UTF-8 that is already valid never calls `Utf8Converter::convert()` — `convertStrict()` returns the bytes after a validity check — so a fake converter would be skipped and the test would get `Ok`.

- [ ] **Step 1: Write the failing tests**

Create `tests/EncodingNormalizer/FakeUtf8Converter.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use Yumo\LogRead\EncodingNormalizer\Utf8Converter;

/**
 * Test double that fails strict conversion and either fails or returns a fixed lossy result.
 *
 * Used to exercise `EncodingNormalizer`’s hard-failure path without depending on mbstring
 * (lossy `mb_scrub` almost always yields valid UTF-8).
 */
final class FakeUtf8Converter implements Utf8Converter
{
    public function __construct(
        /** Exception thrown from every `convert()` call. */
        private readonly \Throwable $convertError,
        /** Exception thrown from `convertLossy()`, or a string returned as the lossy HTML. */
        private readonly \Throwable|string $lossy,
    ) {
    }

    public function convert(string $bytes, string $fromEncoding): string
    {
        throw $this->convertError;
    }

    public function convertLossy(string $bytes, string $fromEncoding): string
    {
        if ($this->lossy instanceof \Throwable) {
            throw $this->lossy;
        }

        return $this->lossy;
    }
}
```

Append to `tests/EncodingNormalizer/EncodingNormalizerTest.php`:

```php
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizerException;
```

```php
    public function test_hard_failure_when_lossy_result_is_not_utf8(): void
    {
        $converter = new FakeUtf8Converter(
            new \RuntimeException('strict failed'),
            "\xFF\xFE",
        );
        $normalizer = new EncodingNormalizer($converter);
        $page = $this->page('<html>ok</html>', 'text/html; charset=ISO-8859-1');

        try {
            $normalizer->normalize($page);
            self::fail('Expected EncodingNormalizerException');
        } catch (EncodingNormalizerException $e) {
            self::assertSame(EncodingError::Conversion, $e->error);
        }
    }

    public function test_hard_failure_when_lossy_convert_throws(): void
    {
        $converter = new FakeUtf8Converter(
            new \RuntimeException('strict failed'),
            new \RuntimeException('lossy failed'),
        );
        $normalizer = new EncodingNormalizer($converter);
        $page = $this->page('<html>ok</html>', 'text/html; charset=ISO-8859-1');

        try {
            $normalizer->normalize($page);
            self::fail('Expected EncodingNormalizerException');
        } catch (EncodingNormalizerException $e) {
            self::assertSame(EncodingError::Conversion, $e->error);
            $previous = $e->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $previous);
            self::assertSame('lossy failed', $previous->getMessage());
        }
    }

    public function test_default_constructor_uses_production_converter(): void
    {
        $page = $this->page('<html>ok</html>', 'text/html; charset=utf-8');
        $outcome = (new EncodingNormalizer())->normalize($page);

        self::assertTrue($outcome->isOk());
        self::assertSame('<html>ok</html>', $outcome->html->html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter EncodingNormalizerTest::test_hard_failure`

Expected: FAIL until `FakeUtf8Converter` is autoloaded and `normalize()` maps both lossy-throw and non-UTF-8 lossy output to `EncodingNormalizerException`. If they already pass on Task 5’s `degradedFromLossy`, that is acceptable — still run this step and record PASS, then continue. Do **not** skip adding the tests.

- [ ] **Step 3: Adjust wrapping only if a test failed**

If `test_hard_failure_when_lossy_result_is_not_utf8` throws `\InvalidArgumentException` from `Utf8Html` instead of `EncodingNormalizerException`, the `mb_check_encoding` guard in `degradedFromLossy` is missing or runs after construction. Check UTF-8 **before** `new Utf8Html(...)` and throw `EncodingNormalizerException` with `$warning` (which is `Conversion` for declared ISO-8859-1 that failed strict convert).

If `test_hard_failure_when_lossy_convert_throws` has `$e->getPrevious()` equal to the strict error rather than `lossy failed`, `degradedFromLossy` is passing the outer `$previous` instead of the lossy `$e`. The Task 5 catch already uses `$e`; keep it that way:

```php
        } catch (EncodingNormalizerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncodingNormalizerException($warning, '', $e);
        }
```

- [ ] **Step 4: Run EncodingNormalizer tests to verify they pass**

Run: `composer test -- --filter 'EncodingNormalizer\\'`

Expected: PASS (all Task 1–6 EncodingNormalizer tests).

- [ ] **Step 5: Run the full test suite**

Run: `composer test`

Expected: PASS. Pre-existing UrlGuard + HttpFetcher tests stay green; new EncodingNormalizer tests are included.

- [ ] **Step 6: Run the quality gates directly (Docker unavailable in this environment)**

Run:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both exit `0` with no diffs / no errors. This is the same check `./bin/quality` runs inside Docker (`composer cs-check` + `composer phpstan`) — run it via `./bin/quality` instead once Docker is available again; do not skip this gate.

If cs-fixer reports diffs, run `vendor/bin/php-cs-fixer fix` then re-run both commands.

Typical PHPStan issues to fix immediately:
- `mb_convert_encoding` return `array|string|false` — keep the `is_string` check from Task 5.
- `getPrevious()` can be null — the hard-fail throw test assigns it to `$previous` and asserts `assertInstanceOf` before `getMessage()`.

- [ ] **Step 7: Manual checklist against the spec**

Confirm each item:

1. `normalize(FetchedPage): EncodingOutcome` exists and throws `EncodingNormalizerException` only when UTF-8 HTML cannot be produced.
2. Detection order is BOM → HTTP charset → meta (first 1024 bytes) → UTF-8 validity → `mb_detect_encoding` → lossy assume UTF-8.
3. HTTP charset wins over a conflicting meta declaration.
4. UTF-8 BOM is stripped from returned HTML; non-UTF-8 BOMs convert via the degraded path.
5. `Utf8Html::$html` always passes `mb_check_encoding(..., 'UTF-8')` and has no leading `EF BB BF`.
6. `Utf8Html::$sourceUrl` equals `FetchedPage::$requestUri` on Ok and Degraded.
7. `EncodingOutcome::ok` has no warning; `degraded` always has an `EncodingError`.
8. Soft problems (`Undeclared`, `Unsupported`, `Conversion` after repair) return `Degraded`, not an exception.
9. No `GuzzleTranscoder` middleware / `convertResponse()`; `ContentTypeExtractor` is used only to parse header/meta.
10. Body meta charset declarations are not rewritten to `utf-8`.
11. No `ArticleExtractor` / `HtmlSanitizer` / `Orchestrator` code was added.
12. `src/index.php` left untouched.

- [ ] **Step 8: Commit**

```bash
git add tests/EncodingNormalizer/FakeUtf8Converter.php tests/EncodingNormalizer/EncodingNormalizerTest.php src/EncodingNormalizer/EncodingNormalizer.php
git commit -m "$(cat <<'EOF'
test(encoding-normalizer): cover hard failure when lossy UTF-8 cannot be produced

EOF
)"
```

If Step 6/7 required production fixes, include those files in the same commit. If nothing changed after the tests already passed in Step 4, still commit the new test file and Fake.

If verification required extra code changes after this commit, make a follow-up commit:

```bash
git add -A
git commit -m "$(cat <<'EOF'
test(encoding-normalizer): close gaps found in Phase 3 exit criteria

EOF
)"
```

---

## Self-review (plan author)

**Spec coverage:**

| Spec area | Task |
|-----------|------|
| `EncodingError` + `defaultMessage()` | Task 1 |
| `EncodingNormalizerException` (hard fail only) | Task 1, Task 6 |
| `EncodingQuality` + `EncodingOutcome` factories / invariants | Task 2 |
| `Utf8Html` + `sourceUrl` + `sourceEncoding` + optional `EncodingSource` | Task 3 |
| `EncodingNormalizer::normalize(FetchedPage): EncodingOutcome` | Task 4 |
| Cascade: BOM → HTTP → meta → UTF-8 default | Task 4 |
| HTTP charset wins over meta | Task 4 |
| UTF-8 BOM strip; BOM wins over HTTP charset | Task 4 |
| `ContentTypeExtractor` for header/meta; no `GuzzleTranscoder::convertResponse()` | Task 4 |
| `Ddeboer\Transcoder\Transcoder` via `Utf8Converter` port | Task 4–5 |
| Optional `mb_detect_encoding` → `Degraded` + `Undeclared` | Task 5 |
| Lossy path → `Degraded` + `Unsupported` / `Conversion` / `Undeclared` | Task 5 |
| Non-UTF-8 BOM → `Degraded` | Task 5 |
| Meta only in first 1024 bytes | Task 5 |
| No body meta rewrite | Task 4 (`charset="windows-1252"` still present) |
| Hard fail when lossy UTF-8 impossible | Task 6 |
| Tests: no HTTP; `FetchedPage` fixtures; always-valid UTF-8; `sourceUrl` | Tasks 4–6 |
| Out of scope (fetcher, extractor, sanitizer, orchestrator, SSRF on `sourceUrl`) | Global Constraints |

**Placeholder scan:** no TBD/TODO implementation steps; every code block is complete; vendor APIs are the installed `fossar/transcoder` 3.x / `fossar/guzzle-transcoder` 0.3.x signatures, not the spec’s pseudocode.

**Type consistency:** `normalize(FetchedPage $page): EncodingOutcome`; `Utf8Html` constructor is `(string $html, string $sourceUrl, string $sourceEncoding, EncodingSource $source)` from Task 3 onward; `EncodingNormalizer::__construct(?Utf8Converter $converter = null)` is unchanged from Task 4 to Task 6; `convertLossy` is added in Task 5 and used by Task 6’s fake. Spec sketches `TranscoderInterface` on the normalizer constructor; this plan wraps it in `Utf8Converter` so the lossy path is stubbable (the spec’s “optional ports”).

**Deviations from the spec’s literal sketches (required for this codebase):**
- Constructor injects `Utf8Converter`, not raw `TranscoderInterface`, because `MbTranscoder` does not throw on invalid sequences and `mb_scrub` almost never fails — hard-fail tests need a stubbable lossy method.
- Direct Composer dependency is `fossar/transcoder` (namespace still `Ddeboer\Transcoder\`), not Packagist `ddeboer/transcoder` (replaced by the fossar fork already in the lockfile).
- `EncodingSource` is included (spec marks it optional) because several Ok paths share `sourceEncoding === UTF-8` and tests cannot otherwise distinguish BOM vs HTTP vs default.
- `EncodingSource::Lossy` has no dedicated fixture: on this PHP, `mb_detect_encoding` reports `Windows-1252` for undeclared 8-bit bytes, so the tested undeclared path is `Detect`.
