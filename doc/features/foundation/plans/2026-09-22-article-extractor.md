# ArticleExtractor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Phase 4 ArticleExtractor so `Utf8Html` becomes an `ExtractResult` — either `Ok` with a `ReadableDocument`, or soft `NoContent` — and hard parse failures throw `ArticleExtractorException`.

**Architecture:** `ArticleExtractor` is a thin adapter over `fivefilters\Readability\Readability` (installed v4.1.0). It builds a `Configuration` from `ExtractPolicy` plus `Utf8Html::$sourceUrl`, maps the vendor `Article` onto `PlainText` and `ReadableDocument`, and never lets vendor types or vendor exceptions leave the class. Empty and whitespace-only HTML return `ExtractResult::noContent()` before Readability runs. A metadata-only vendor article (`hasContent() === false`) is the same soft result. `ParseException::tooManyElements()` becomes `ArticleExtractorError::TooLarge`.

**Tech Stack:** PHP `^8.5`, PHPUnit `^11`, `fivefilters/readability.php` `^4.0` (lockfile v4.1.0), PSR-3 `Psr\Log\LoggerInterface` (already installed via `psr/log` 3.0.2), PSR-4 `Yumo\LogRead\` → `src/`.

**Spec:** `doc/features/foundation/specs/ArticleExtractor.md` (also `doc/features/foundation/feature.md` Phase 4)

## Global Constraints

- PHP `^8.5`. Namespace root: `Yumo\LogRead\` → `src/`; tests: `Yumo\LogRead\Tests\` → `tests/`.
- Documentation, identifiers, commit messages, and PHPDoc in **English**. Follow `.cursor/rules/php-docs.mdc`: complete sentences; do **not** describe types as “the fourth pipeline stage”.
- **Parse, don’t validate:** `extract()` returns `ExtractResult`. `ReadableDocument` exists only when article HTML was found. Soft “nothing to extract” is `ExtractResult::NoContent`, including empty / whitespace-only HTML. There is no `EmptyInput` error kind.
- `ArticleExtractorException` is for hard failures only: `TooLarge` (over `maxElemsToParse`) and `Unexpected` (any other throwable from the adapter).
- Input is `Utf8Html` only. `sourceUrl` is copied from `Utf8Html::$sourceUrl`. Do not add a `baseUrl` argument. Do not read `GuardResult::$original`.
- Vendor `fivefilters\Readability\Article` and `ParseException` stay inside `ArticleExtractor`. Callers see `PlainText`, `ReadableDocument`, and `ExtractResult`.
- Metadata is the vendor’s fused fields (`title`, `excerpt`, `siteName`) passed through `PlainText`. Do not add an Open Graph or JSON-LD parser. Do not expose `byline`, `dir`, `lang`, `publishedTime`, `image`, `images`, or `contentElement`.
- Leave Readability defaults for every option the policy does not list. In particular do not set `disableJSONLD` (vendor default `false` stays on).
- `ExtractPolicy` defaults: `debug=false`, `fixRelativeURLs=true`, `charThreshold=500`, `maxElemsToParse=30000`. `0` is a legal unlimited element cap. Negative limits throw `\InvalidArgumentException`.
- Optional PSR-3 logger is a constructor argument on `ArticleExtractor`, not a policy field. Pass it into Readability only when `ExtractPolicy::$debug` is true. Readability logs to a PSR-3 logger even when `debug` is false, so a debug-off extractor must pass `logger: null`.
- `PlainText::raw()` is the storage form. `PlainText::html()` is for embedding in HTML. Do not persist `html()`.
- This stage does not sanitize HTML. Do not implement `HtmlSanitizer` or `Orchestrator`. Leave `src/index.php` untouched.
- Unit tests build `Utf8Html` fixtures. No HTTP.
- **Vendor behavior this plan is written against (readability.php v4.1.0):** `hasContent()` is false only when every grab attempt has text length 0 (empty body, whitespace body, hidden/script-only). A nav that contains visible words is still content after Readability’s flag retries, even when shorter than `charThreshold`. The NoContent fixture is an empty `<body>` (with or without meta), not a nav full of links. A paragraph repeated 20 times (`The quick brown fox jumps over the lazy dog. `) clears the default `charThreshold` of 500 and drops `<nav>` / `<footer>` siblings. `ParseException` has no error code; `emptyInput()` message is `No HTML content provided.` and `tooManyElements()` starts with `Aborting parsing document;`.
- **Environment note for this plan's execution:** Docker is unavailable in the current environment, so every command below runs PHP/Composer directly on the host (`vendor/bin/phpunit`, `composer test`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`) instead of the `./bin/*` Docker wrappers. When Docker is available again, `./bin/quality` must still pass on the final code — the commands are equivalent, only the execution wrapper differs.

---

## File Structure

| Path | Responsibility |
|------|----------------|
| `src/ArticleExtractor/ArticleExtractorError.php` | Hard-failure kind (`TooLarge`, `Unexpected`) + `defaultMessage()` |
| `src/ArticleExtractor/ArticleExtractorException.php` | Hard failure only; carries `ArticleExtractorError` and the vendor throwable as `$previous` |
| `src/ArticleExtractor/PlainText.php` | Tag-stripped metadata; `raw()` for storage, `html()` for markup |
| `src/ArticleExtractor/ExtractPolicy.php` | Immutable Readability knobs; rejects negative limits |
| `src/ArticleExtractor/ReadableDocument.php` | Article HTML plus `PlainText` metadata and `sourceUrl` |
| `src/ArticleExtractor/ExtractStatus.php` | Discriminant `Ok` \| `NoContent` |
| `src/ArticleExtractor/ExtractResult.php` | Immutable sum type; factories enforce Ok/NoContent invariants |
| `src/ArticleExtractor/ArticleExtractor.php` | Adapter: empty short-circuit, Readability parse, metadata map, exception map |
| `tests/ArticleExtractor/ArticleExtractorExceptionTest.php` | Default vs custom message, `$previous` |
| `tests/ArticleExtractor/PlainTextTest.php` | `strip_tags` + trim, null/blank optional factory, `raw()` vs `html()` |
| `tests/ArticleExtractor/ExtractPolicyTest.php` | Defaults and negative-limit rejection |
| `tests/ArticleExtractor/ReadableDocumentTest.php` | Field storage |
| `tests/ArticleExtractor/ExtractResultTest.php` | `ok()` / `noContent()` factories and status helpers |
| `tests/ArticleExtractor/ArticleExtractorTest.php` | `extract()` Ok, NoContent, TooLarge, relative URLs, logger gating |
| `tests/ArticleExtractor/RecordingLogger.php` | PSR-3 spy used to prove the logger is passed only when `debug` is true |

---

### Task 1: `ArticleExtractorError` + `ArticleExtractorException`

**Files:**
- Create: `src/ArticleExtractor/ArticleExtractorError.php`
- Create: `src/ArticleExtractor/ArticleExtractorException.php`
- Test: `tests/ArticleExtractor/ArticleExtractorExceptionTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `enum ArticleExtractorError { case TooLarge; case Unexpected; public function defaultMessage(): string }`
  - `final class ArticleExtractorException extends \RuntimeException { public function __construct(public readonly ArticleExtractorError $error, string $message = '', ?\Throwable $previous = null) }`
  - Default messages (verbatim from the spec):
    - `TooLarge` → `HTML document exceeds the configured element limit`
    - `Unexpected` → `Article extraction failed unexpectedly`

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/ArticleExtractorExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorError;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorException;

final class ArticleExtractorExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(ArticleExtractorError $error, string $expected): void
    {
        $exception = new ArticleExtractorException($error);

        self::assertSame($expected, $exception->getMessage());
        self::assertSame($error, $exception->error);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @return array<string, array{ArticleExtractorError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'too large' => [
                ArticleExtractorError::TooLarge,
                'HTML document exceeds the configured element limit',
            ],
            'unexpected' => [
                ArticleExtractorError::Unexpected,
                'Article extraction failed unexpectedly',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $exception = new ArticleExtractorException(
            ArticleExtractorError::TooLarge,
            'DOM exceeded the cap',
            $previous,
        );

        self::assertSame('DOM exceeded the cap', $exception->getMessage());
        self::assertSame(ArticleExtractorError::TooLarge, $exception->error);
        self::assertSame($previous, $exception->getPrevious());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ArticleExtractorExceptionTest`

Expected: FAIL with class not found (`ArticleExtractorException` or `ArticleExtractorError`).

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/ArticleExtractorError.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This enum names hard failures that abort article extraction for one document.
 *
 * A page with no article body is not one of these cases. That outcome is
 * `ExtractResult::noContent()`.
 */
enum ArticleExtractorError
{
    /** This case applies when the document has more elements than `ExtractPolicy::$maxElemsToParse` allows. */
    case TooLarge;

    /** This case applies when the adapter catches a throwable that is not an empty-input or element-limit parse failure. */
    case Unexpected;

    /**
     * This method returns the standard message for this failure when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::TooLarge => 'HTML document exceeds the configured element limit',
            self::Unexpected => 'Article extraction failed unexpectedly',
        };
    }
}
```

Create `src/ArticleExtractor/ArticleExtractorException.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This exception reports that article extraction aborted for one document.
 *
 * Inspect `$error` to tell an oversized document from an unexpected failure.
 * A page with no article body does not throw: that is `ExtractResult::noContent()`.
 */
final class ArticleExtractorException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified extraction failure.
     */
    public function __construct(
        /** This value identifies why extraction aborted. */
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

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ArticleExtractorExceptionTest`

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ArticleExtractorError.php src/ArticleExtractor/ArticleExtractorException.php tests/ArticleExtractor/ArticleExtractorExceptionTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): add tagged hard-failure exception

EOF
)"
```

---

### Task 2: `PlainText`

**Files:**
- Create: `src/ArticleExtractor/PlainText.php`
- Test: `tests/ArticleExtractor/PlainTextTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `final readonly class PlainText`
  - `public static function fromUntrusted(string $value): self` — `trim(strip_tags($value))`, empty string allowed
  - `public static function fromUntrustedNullable(?string $value): ?self` — `null` when `$value` is `null` or the stripped text is `''`
  - `public function raw(): string`
  - `public function html(): string` — `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
  - `public function __toString(): string` — same as `raw()`

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/PlainTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;

final class PlainTextTest extends TestCase
{
    public function test_from_untrusted_strips_tags_and_trims(): void
    {
        $text = PlainText::fromUntrusted('  <b>Hi</b>  ');

        self::assertSame('Hi', $text->raw());
        self::assertSame('Hi', (string) $text);
    }

    public function test_from_untrusted_keeps_empty_string(): void
    {
        $text = PlainText::fromUntrusted('  <b></b>  ');

        self::assertSame('', $text->raw());
    }

    public function test_html_escapes_markup_characters(): void
    {
        $text = PlainText::fromUntrusted('Tom & Jerry\'s "show"');

        self::assertSame('Tom & Jerry\'s "show"', $text->raw());
        self::assertSame('Tom &amp; Jerry&#039;s &quot;show&quot;', $text->html());
    }

    public function test_nullable_factory_returns_null_for_null_and_blank(): void
    {
        self::assertNull(PlainText::fromUntrustedNullable(null));
        self::assertNull(PlainText::fromUntrustedNullable(''));
        self::assertNull(PlainText::fromUntrustedNullable('   '));
        self::assertNull(PlainText::fromUntrustedNullable('<em></em>'));
    }

    public function test_nullable_factory_returns_text_when_something_remains(): void
    {
        $text = PlainText::fromUntrustedNullable('  <em>Excerpt</em>  ');

        self::assertInstanceOf(PlainText::class, $text);
        self::assertSame('Excerpt', $text->raw());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter PlainTextTest`

Expected: FAIL with class `PlainText` not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/PlainText.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds one piece of metadata as plain text, with tags already removed.
 *
 * Store and log `raw()`. Use `html()` only when the text is placed in an HTML
 * body or attribute. The two forms differ: `html()` is escaped, and saving that
 * escaped string would encode it a second time on the next read.
 *
 * `$title = PlainText::fromUntrusted($articleTitle);`
 */
final readonly class PlainText
{
    /**
     * This constructor stores text that has already been stripped and trimmed.
     */
    private function __construct(
        /** This value holds the tag-stripped UTF-8 text. */
        private string $value,
    ) {
    }

    /**
     * This method builds plain text from untrusted metadata, stripping tags and trimming whitespace.
     *
     * An empty result is kept. Call `fromUntrustedNullable()` when a missing excerpt or site name should be null.
     */
    public static function fromUntrusted(string $value): self
    {
        return new self(trim(strip_tags($value)));
    }

    /**
     * This method builds plain text from optional metadata, returning null when the value is missing or blank after stripping.
     */
    public static function fromUntrustedNullable(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $text = self::fromUntrusted($value);
        if ($text->raw() === '') {
            return null;
        }

        return $text;
    }

    /**
     * This method returns the tag-stripped text for storage, JSON, or logs.
     */
    public function raw(): string
    {
        return $this->value;
    }

    /**
     * This method returns the text escaped for an HTML body or attribute.
     *
     * The escaping uses `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8. Do not store this return value.
     */
    public function html(): string
    {
        return htmlspecialchars($this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * This method returns the same string as `raw()` so logs and JSON do not persist the escaped form by accident.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter PlainTextTest`

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/PlainText.php tests/ArticleExtractor/PlainTextTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): add PlainText for stripped metadata

EOF
)"
```

---

### Task 3: `ExtractPolicy`

**Files:**
- Create: `src/ArticleExtractor/ExtractPolicy.php`
- Test: `tests/ArticleExtractor/ExtractPolicyTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `final readonly class ExtractPolicy`
  - `public function __construct(public bool $debug = false, public bool $fixRelativeURLs = true, public int $charThreshold = 500, public int $maxElemsToParse = 30000)`
  - Throws `\InvalidArgumentException` with message `charThreshold must not be negative` when `$charThreshold < 0`
  - Throws `\InvalidArgumentException` with message `maxElemsToParse must not be negative` when `$maxElemsToParse < 0`
  - `0` is valid for both integers (`maxElemsToParse = 0` means no element cap)

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/ExtractPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;

final class ExtractPolicyTest extends TestCase
{
    public function test_defaults_match_the_project_policy(): void
    {
        $policy = new ExtractPolicy();

        self::assertFalse($policy->debug);
        self::assertTrue($policy->fixRelativeURLs);
        self::assertSame(500, $policy->charThreshold);
        self::assertSame(30000, $policy->maxElemsToParse);
    }

    public function test_zero_limits_are_explicit_opt_outs(): void
    {
        $policy = new ExtractPolicy(charThreshold: 0, maxElemsToParse: 0);

        self::assertSame(0, $policy->charThreshold);
        self::assertSame(0, $policy->maxElemsToParse);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_negative_limits(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): ExtractPolicy, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'negative char threshold' => [
                static fn (): ExtractPolicy => new ExtractPolicy(charThreshold: -1),
                'charThreshold must not be negative',
            ],
            'negative element cap' => [
                static fn (): ExtractPolicy => new ExtractPolicy(maxElemsToParse: -1),
                'maxElemsToParse must not be negative',
            ],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ExtractPolicyTest`

Expected: FAIL with class `ExtractPolicy` not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/ExtractPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds the Readability knobs for one `ArticleExtractor`.
 *
 * Limits are checked here so a negative cap cannot reach the vendor.
 * `charThreshold` is the vendor’s minimum article length before it retries
 * with softer flags; it does not truncate the article. `maxElemsToParse`
 * stops parsing when the DOM is larger than this many elements. Zero means
 * no element cap.
 */
final readonly class ExtractPolicy
{
    /**
     * This constructor stores the knobs and rejects negative limits.
     *
     * @throws \InvalidArgumentException When `$charThreshold` or `$maxElemsToParse` is negative
     */
    public function __construct(
        /** This value enables Readability’s `error_log` debug output, and it is also the switch that allows a PSR-3 logger to be passed in. */
        public bool $debug = false,
        /** This value rewrites relative URLs against `Utf8Html::$sourceUrl` when true. */
        public bool $fixRelativeURLs = true,
        /** This value is the minimum article text length Readability wants before it stops retrying. */
        public int $charThreshold = 500,
        /** This value is the maximum number of DOM elements to parse. Zero disables the cap. */
        public int $maxElemsToParse = 30000,
    ) {
        if ($charThreshold < 0) {
            throw new \InvalidArgumentException('charThreshold must not be negative');
        }
        if ($maxElemsToParse < 0) {
            throw new \InvalidArgumentException('maxElemsToParse must not be negative');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ExtractPolicyTest`

Expected: PASS (3 tests, 4 assertions on the data provider cases).

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ExtractPolicy.php tests/ArticleExtractor/ExtractPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): add self-checking ExtractPolicy

EOF
)"
```

---

### Task 4: `ReadableDocument`

**Files:**
- Create: `src/ArticleExtractor/ReadableDocument.php`
- Test: `tests/ArticleExtractor/ReadableDocumentTest.php`

**Interfaces:**
- Consumes: `PlainText` from Task 2 (`fromUntrusted`, `fromUntrustedNullable`)
- Produces:
  - `final readonly class ReadableDocument`
  - `public function __construct(public PlainText $title, public ?PlainText $excerpt, public ?PlainText $siteName, public string $content, public string $sourceUrl)`
  - Public constructor, matching the spec sketch. `ArticleExtractor` is the production caller and calls it only when the vendor article has content. No extra validation in this task.

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/ReadableDocumentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

final class ReadableDocumentTest extends TestCase
{
    public function test_stores_article_fields(): void
    {
        $title = PlainText::fromUntrusted('Story Title');
        $excerpt = PlainText::fromUntrusted('A short excerpt');
        $document = new ReadableDocument(
            title: $title,
            excerpt: $excerpt,
            siteName: null,
            content: '<p>Body</p>',
            sourceUrl: 'https://ex.com/a',
        );

        self::assertSame($title, $document->title);
        self::assertSame($excerpt, $document->excerpt);
        self::assertNull($document->siteName);
        self::assertSame('<p>Body</p>', $document->content);
        self::assertSame('https://ex.com/a', $document->sourceUrl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ReadableDocumentTest`

Expected: FAIL with class `ReadableDocument` not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/ReadableDocument.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds extracted article HTML plus the metadata that belongs with it.
 *
 * `$content` is an HTML fragment from Readability. It has not been checked for
 * scripts or unsafe URLs. `$title`, `$excerpt`, and `$siteName` are plain text:
 * store `raw()`, and use `html()` when rendering them as text.
 */
final readonly class ReadableDocument
{
    /**
     * This constructor stores the article fragment and its metadata.
     */
    public function __construct(
        /** This value holds the article title. `raw()` may be empty when Readability found none. */
        public PlainText $title,
        /** This value holds the description or short excerpt, or null when none was found. */
        public ?PlainText $excerpt,
        /** This value holds the site name, or null when none was found. */
        public ?PlainText $siteName,
        /** This value holds the article HTML fragment. It is not safe to print as trusted HTML yet. */
        public string $content,
        /** This value holds the page URL the HTML was fetched from, used as the base for relative links. */
        public string $sourceUrl,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ReadableDocumentTest`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ReadableDocument.php tests/ArticleExtractor/ReadableDocumentTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): add ReadableDocument for extracted articles

EOF
)"
```

---

### Task 5: `ExtractStatus` + `ExtractResult`

**Files:**
- Create: `src/ArticleExtractor/ExtractStatus.php`
- Create: `src/ArticleExtractor/ExtractResult.php`
- Test: `tests/ArticleExtractor/ExtractResultTest.php`

**Interfaces:**
- Consumes:
  - `PlainText` from Task 2
  - `ReadableDocument` from Task 4
- Produces:
  - `enum ExtractStatus { case Ok; case NoContent; }`
  - `final readonly class ExtractResult`
  - `public static function ok(ReadableDocument $document): self` — status `Ok`; `document`, `title`, `excerpt`, `siteName`, and `sourceUrl` copied from `$document`
  - `public static function noContent(string $sourceUrl, ?PlainText $title = null, ?PlainText $excerpt = null, ?PlainText $siteName = null): self` — status `NoContent`; `document` is null; omitted `$title` becomes `PlainText::fromUntrusted('')`
  - `public function isOk(): bool`
  - `public function isNoContent(): bool`
  - Private constructor throws `\InvalidArgumentException` `Ok result requires ReadableDocument` when status is `Ok` and `document` is null
  - Private constructor throws `\InvalidArgumentException` `NoContent must not carry ReadableDocument` when status is `NoContent` and `document` is not null

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/ExtractResultTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractResult;
use Yumo\LogRead\ArticleExtractor\ExtractStatus;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

final class ExtractResultTest extends TestCase
{
    public function test_ok_factory_mirrors_the_document(): void
    {
        $document = $this->document();
        $result = ExtractResult::ok($document);

        self::assertSame(ExtractStatus::Ok, $result->status);
        self::assertTrue($result->isOk());
        self::assertFalse($result->isNoContent());
        self::assertSame($document, $result->document);
        self::assertSame($document->title, $result->title);
        self::assertSame($document->excerpt, $result->excerpt);
        self::assertSame($document->siteName, $result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    public function test_no_content_defaults_title_to_empty_plain_text(): void
    {
        $result = ExtractResult::noContent('https://ex.com/a');

        self::assertSame(ExtractStatus::NoContent, $result->status);
        self::assertTrue($result->isNoContent());
        self::assertFalse($result->isOk());
        self::assertNull($result->document);
        self::assertInstanceOf(PlainText::class, $result->title);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    public function test_no_content_keeps_metadata_when_provided(): void
    {
        $title = PlainText::fromUntrusted('OG Title');
        $excerpt = PlainText::fromUntrusted('Just a blurb');
        $siteName = PlainText::fromUntrusted('Example News');
        $result = ExtractResult::noContent('https://ex.com/a', $title, $excerpt, $siteName);

        self::assertNull($result->document);
        self::assertSame($title, $result->title);
        self::assertSame($excerpt, $result->excerpt);
        self::assertSame($siteName, $result->siteName);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    private function document(): ReadableDocument
    {
        return new ReadableDocument(
            title: PlainText::fromUntrusted('Story Title'),
            excerpt: PlainText::fromUntrusted('A short excerpt'),
            siteName: PlainText::fromUntrusted('Example News'),
            content: '<p>Body</p>',
            sourceUrl: 'https://ex.com/a',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ExtractResultTest`

Expected: FAIL with class `ExtractResult` or `ExtractStatus` not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/ExtractStatus.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This enum distinguishes an extracted article from a page that had no article body.
 */
enum ExtractStatus
{
    /** This case applies when `ExtractResult::$document` holds the article. */
    case Ok;

    /** This case applies when no article body was found. Metadata may still be present. */
    case NoContent;
}
```

Create `src/ArticleExtractor/ExtractResult.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class is the outcome of extracting an article from UTF-8 HTML.
 *
 * `Ok` always carries a `ReadableDocument`. `NoContent` never does.
 * Title, excerpt, site name, and source URL are also stored on the result
 * so a no-content page can still expose the metadata Readability found.
 *
 * `$result = $extractor->extract($html);`
 */
final readonly class ExtractResult
{
    /**
     * This constructor stores one outcome and rejects an Ok without a document or a NoContent that has one.
     *
     * @throws \InvalidArgumentException When the status and `$document` disagree
     */
    private function __construct(
        /** This value selects Ok or NoContent. */
        public ExtractStatus $status,
        /** This value holds the article when status is Ok, and null when status is NoContent. */
        public ?ReadableDocument $document = null,
        /** This value holds the title. On NoContent it is empty plain text when Readability found none. */
        public ?PlainText $title = null,
        /** This value holds the excerpt, or null when none was found. */
        public ?PlainText $excerpt = null,
        /** This value holds the site name, or null when none was found. */
        public ?PlainText $siteName = null,
        /** This value holds the page URL copied from the input HTML. */
        public string $sourceUrl = '',
    ) {
        if ($status === ExtractStatus::Ok && $document === null) {
            throw new \InvalidArgumentException('Ok result requires ReadableDocument');
        }
        if ($status === ExtractStatus::NoContent && $document !== null) {
            throw new \InvalidArgumentException('NoContent must not carry ReadableDocument');
        }
    }

    /**
     * This method builds an Ok result from an article document and copies its metadata onto the result.
     */
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

    /**
     * This method builds a NoContent result. A missing title becomes empty plain text.
     */
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

    /**
     * This method returns whether an article document is present.
     */
    public function isOk(): bool
    {
        return $this->status === ExtractStatus::Ok;
    }

    /**
     * This method returns whether no article body was found.
     */
    public function isNoContent(): bool
    {
        return $this->status === ExtractStatus::NoContent;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ExtractResultTest`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ExtractStatus.php src/ArticleExtractor/ExtractResult.php tests/ArticleExtractor/ExtractResultTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): add ExtractResult Ok and NoContent

EOF
)"
```

---

### Task 6: `ArticleExtractor` happy path and empty input

**Files:**
- Create: `src/ArticleExtractor/ArticleExtractor.php`
- Create: `tests/ArticleExtractor/ArticleExtractorTest.php`

**Interfaces:**
- Consumes:
  - `ExtractPolicy` from Task 3
  - `ReadableDocument` from Task 4
  - `ExtractResult`, `ExtractStatus` from Task 5
  - `PlainText` from Task 2
  - `ArticleExtractorException`, `ArticleExtractorError` from Task 1
  - `Yumo\LogRead\EncodingNormalizer\Utf8Html` constructor: `(string $html, string $sourceUrl, string $sourceEncoding, EncodingSource $source)`
  - `fivefilters\Readability\Readability::parse(string): Article`
  - `fivefilters\Readability\Configuration` named args used here: `charThreshold`, `fixRelativeURLs`, `originalURL`, `maxElemsToParse`
- Produces (signature is final; later tasks only fill in branches):
  - `final class ArticleExtractor`
  - `public function __construct(?ExtractPolicy $policy = null, ?\Psr\Log\LoggerInterface $logger = null)`
  - `public function extract(Utf8Html $html): ExtractResult`
  - `@throws ArticleExtractorException`

This task’s `extract()` handles two paths:

1. `trim($html->html) === ''` → `ExtractResult::noContent($html->sourceUrl)`.
2. Vendor `hasContent()` → `ExtractResult::ok(ReadableDocument)` with `PlainText` metadata, `content` from `$article->content`, and `sourceUrl` from `$html->sourceUrl`.

Configuration in this task is intentionally partial, so Task 8’s tests can fail first:

- `charThreshold` comes from the policy.
- `fixRelativeURLs` is hardcoded `true`, and `originalURL` is always `$html->sourceUrl`.
- `maxElemsToParse` is hardcoded `30000` (the project default), not the policy property.
- `debug` and `logger` are not passed. Store `$logger` on the object anyway so the constructor signature does not change later.

When `hasContent()` is false, throw `ArticleExtractorException(ArticleExtractorError::Unexpected)`. Task 7 replaces that branch with `NoContent`. Do not catch `ParseException` yet.

- [ ] **Step 1: Write the failing test**

Create `tests/ArticleExtractor/ArticleExtractorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractStatus;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class ArticleExtractorTest extends TestCase
{
    public function test_extracts_article_and_drops_chrome(): void
    {
        $result = (new ArticleExtractor())->extract($this->page($this->articleHtml()));

        self::assertSame(ExtractStatus::Ok, $result->status);
        self::assertTrue($result->isOk());
        self::assertNotNull($result->document);
        self::assertSame('Story Title', $result->document->title->raw());
        self::assertNotNull($result->document->excerpt);
        self::assertSame('A short excerpt', $result->document->excerpt->raw());
        self::assertNotNull($result->document->siteName);
        self::assertSame('Example News', $result->document->siteName->raw());
        self::assertSame('https://ex.com/a', $result->document->sourceUrl);
        self::assertSame('https://ex.com/a', $result->sourceUrl);
        self::assertStringContainsString('The quick brown fox jumps over the lazy dog.', $result->document->content);
        self::assertStringContainsString('href="https://ex.com/x"', $result->document->content);
        self::assertStringNotContainsString('Home About Contact', $result->document->content);
        self::assertStringNotContainsString('Copyright 2026', $result->document->content);
    }

    #[DataProvider('blankHtml')]
    public function test_blank_html_is_no_content(string $html): void
    {
        $result = (new ArticleExtractor())->extract($this->page($html, 'https://ex.com/empty'));

        self::assertSame(ExtractStatus::NoContent, $result->status);
        self::assertTrue($result->isNoContent());
        self::assertNull($result->document);
        self::assertNotNull($result->title);
        self::assertSame('', $result->title->raw());
        self::assertNull($result->excerpt);
        self::assertNull($result->siteName);
        self::assertSame('https://ex.com/empty', $result->sourceUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankHtml(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'whitespace' => ["\n\t  "],
        ];
    }

    private function page(string $html, string $sourceUrl = 'https://ex.com/a'): Utf8Html
    {
        return new Utf8Html(
            html: $html,
            sourceUrl: $sourceUrl,
            sourceEncoding: 'UTF-8',
            source: EncodingSource::Utf8Default,
        );
    }

    /**
     * This helper builds a page whose article text is long enough for the default character threshold of 500.
     *
     * Twenty repetitions are about 900 characters. On readability.php 4.1.0 that length keeps the article and drops the nav and footer.
     */
    private function articleHtml(): string
    {
        $paragraph = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);

        return '<!DOCTYPE html><html><head><title>Story Title</title>'
            . '<meta property="og:description" content="A short excerpt">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body>'
            . '<nav>Home About Contact</nav>'
            . '<article><h1>Story Title</h1><p>' . $paragraph . '</p>'
            . '<p><a href="/x">more</a></p></article>'
            . '<footer>Copyright 2026</footer>'
            . '</body></html>';
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ArticleExtractorTest`

Expected: FAIL with class `ArticleExtractor` not found.

- [ ] **Step 3: Write minimal implementation**

Create `src/ArticleExtractor/ArticleExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use Psr\Log\LoggerInterface;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

/**
 * This class extracts article HTML and a few metadata fields from a UTF-8 page.
 *
 * It wraps Readability and maps the vendor article onto `ReadableDocument`.
 * Blank HTML returns `ExtractResult::noContent()` without calling Readability.
 * The article HTML is not checked for scripts or unsafe URLs.
 *
 * `$result = (new ArticleExtractor())->extract($utf8Html);`
 */
final class ArticleExtractor
{
    /** This value holds the Readability knobs for every `extract()` call. */
    private ExtractPolicy $policy;

    /** This value holds an optional PSR-3 logger. It is passed to Readability only when `$policy->debug` is true. */
    private ?LoggerInterface $logger;

    /**
     * This constructor stores the policy and logger. Omitted arguments use a default `ExtractPolicy` and no logger.
     */
    public function __construct(
        ?ExtractPolicy $policy = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->policy = $policy ?? new ExtractPolicy();
        $this->logger = $logger;
    }

    /**
     * This method extracts an article from `$html`, or returns NoContent when the document is blank.
     *
     * @throws ArticleExtractorException When extraction aborts
     */
    public function extract(Utf8Html $html): ExtractResult
    {
        if (trim($html->html) === '') {
            return ExtractResult::noContent($html->sourceUrl);
        }

        $article = (new Readability($this->configurationFor($html)))->parse($html->html);
        if (!$article->hasContent()) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected);
        }

        $content = $article->content;
        if (!is_string($content)) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected);
        }

        return ExtractResult::ok(new ReadableDocument(
            title: PlainText::fromUntrusted($article->title),
            excerpt: PlainText::fromUntrustedNullable($article->excerpt),
            siteName: PlainText::fromUntrustedNullable($article->siteName),
            content: $content,
            sourceUrl: $html->sourceUrl,
        ));
    }

    /**
     * This method builds the vendor configuration for one page.
     *
     * Relative URLs are rewritten against `$html->sourceUrl`. The element cap
     * is the project default of 30000 until a later change reads it from the policy.
     */
    private function configurationFor(Utf8Html $html): Configuration
    {
        return new Configuration(
            charThreshold: $this->policy->charThreshold,
            fixRelativeURLs: true,
            originalURL: $html->sourceUrl,
            maxElemsToParse: 30000,
        );
    }
}
```

The `$logger` property is unread in this task. That is temporary: Task 8 passes it into `Configuration`. PHPStan at the end of Task 8 must see it used. Do not delete the property to silence an unused-property notice if one appears before Task 8.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ArticleExtractorTest`

Expected: PASS (2 tests; the blank-html provider runs 3 cases).

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ArticleExtractor.php tests/ArticleExtractor/ArticleExtractorTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): extract article HTML and skip blank input

EOF
)"
```

---

### Task 7: NoContent with metadata

**Files:**
- Modify: `src/ArticleExtractor/ArticleExtractor.php` (`extract()`)
- Modify: `tests/ArticleExtractor/ArticleExtractorTest.php`

**Interfaces:**
- Consumes: `ArticleExtractor::extract(Utf8Html $html): ExtractResult` from Task 6
- Produces: the same signature. `hasContent() === false` now returns `ExtractResult::noContent($html->sourceUrl, title, excerpt, siteName)` using `PlainText::fromUntrusted($article->title)` and `PlainText::fromUntrustedNullable` for excerpt and site name. The `content === null` guard after `hasContent()` stays a hard `Unexpected` throw.

On readability.php 4.1.0 an empty `<body>` with Open Graph / description meta returns `hasContent() === false` and still fills `title`, `excerpt`, and `siteName`. A nav that contains visible words does **not** do this; do not use that as the fixture.

- [ ] **Step 1: Write the failing test**

Append these methods to `ArticleExtractorTest`:

```php
    public function test_empty_body_with_metadata_is_no_content(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="OG Title">'
            . '<meta name="description" content="Just a blurb">'
            . '<meta property="og:site_name" content="Example News">'
            . '</head><body></body></html>';

        $result = (new ArticleExtractor())->extract($this->page($html));

        self::assertTrue($result->isNoContent());
        self::assertNull($result->document);
        self::assertNotNull($result->title);
        self::assertSame('OG Title', $result->title->raw());
        self::assertNotNull($result->excerpt);
        self::assertSame('Just a blurb', $result->excerpt->raw());
        self::assertNotNull($result->siteName);
        self::assertSame('Example News', $result->siteName->raw());
        self::assertSame('https://ex.com/a', $result->sourceUrl);
    }

    public function test_metadata_tags_are_stripped_on_no_content(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="&lt;b&gt;Hi &amp; Bye&lt;/b&gt;">'
            . '<meta name="description" content="&lt;em&gt;Excerpt&lt;/em&gt;">'
            . '<meta property="og:site_name" content="&lt;i&gt;News&lt;/i&gt;">'
            . '</head><body></body></html>';

        $result = (new ArticleExtractor())->extract($this->page($html));

        self::assertTrue($result->isNoContent());
        self::assertNotNull($result->title);
        self::assertSame('Hi & Bye', $result->title->raw());
        self::assertSame('Hi &amp; Bye', $result->title->html());
        self::assertNotNull($result->excerpt);
        self::assertSame('Excerpt', $result->excerpt->raw());
        self::assertNotNull($result->siteName);
        self::assertSame('News', $result->siteName->raw());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter 'test_empty_body_with_metadata_is_no_content|test_metadata_tags_are_stripped_on_no_content'`

Expected: FAIL with `ArticleExtractorException` and error `Unexpected` (Task 6 throws when `hasContent()` is false).

- [ ] **Step 3: Write minimal implementation**

Replace the `hasContent()` branch inside `extract()` so the method is:

```php
    public function extract(Utf8Html $html): ExtractResult
    {
        if (trim($html->html) === '') {
            return ExtractResult::noContent($html->sourceUrl);
        }

        $article = (new Readability($this->configurationFor($html)))->parse($html->html);
        $title = PlainText::fromUntrusted($article->title);
        $excerpt = PlainText::fromUntrustedNullable($article->excerpt);
        $siteName = PlainText::fromUntrustedNullable($article->siteName);

        if (!$article->hasContent()) {
            return ExtractResult::noContent($html->sourceUrl, $title, $excerpt, $siteName);
        }

        $content = $article->content;
        if (!is_string($content)) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected);
        }

        return ExtractResult::ok(new ReadableDocument(
            title: $title,
            excerpt: $excerpt,
            siteName: $siteName,
            content: $content,
            sourceUrl: $html->sourceUrl,
        ));
    }
```

Leave `configurationFor()` unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ArticleExtractorTest`

Expected: PASS. Task 6 cases stay green.

- [ ] **Step 5: Commit**

```bash
git add src/ArticleExtractor/ArticleExtractor.php tests/ArticleExtractor/ArticleExtractorTest.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): return NoContent when the page has no article body

EOF
)"
```

---

### Task 8: Element cap, relative URLs off, and debug logger

**Files:**
- Modify: `src/ArticleExtractor/ArticleExtractor.php`
- Modify: `tests/ArticleExtractor/ArticleExtractorTest.php`
- Create: `tests/ArticleExtractor/RecordingLogger.php`

**Interfaces:**
- Consumes: `ArticleExtractor::extract(Utf8Html $html): ExtractResult` from Task 7, `ExtractPolicy` from Task 3, `ArticleExtractorException` / `ArticleExtractorError` from Task 1
- Produces: the same `extract()` signature, now with:
  - `Configuration` reads `debug`, `maxElemsToParse`, and `fixRelativeURLs` from the policy
  - `originalURL` is `$html->sourceUrl` only when `fixRelativeURLs` is true, otherwise `null`
  - `logger` is `$this->logger` only when `debug` is true, otherwise `null`
  - `fivefilters\Readability\ParseException` whose message is `No HTML content provided.` → `ExtractResult::noContent($html->sourceUrl)` (no metadata; this is the vendor empty-input path)
  - `ParseException` whose message starts with `Aborting parsing document;` → `ArticleExtractorException(ArticleExtractorError::TooLarge, previous: $exception)` and the default message `HTML document exceeds the configured element limit`
  - any other `ParseException` or `\Throwable` → `ArticleExtractorException(ArticleExtractorError::Unexpected, previous: $exception)`

`ParseException` has no status code in v4.1.0. Those two message checks are the public discriminator for `ParseException::emptyInput()` and `ParseException::tooManyElements()`. The empty-input catch is unreachable while the `trim` short-circuit stays, because the vendor throws `emptyInput()` only when `trim($document) === ''`. Keep both: the short-circuit avoids a parse, and the catch is the spec’s mapping if `parse()` is still reached.

Do not add a parser port. `Unexpected` stays in the catch for throwables the HTML fixtures do not produce.

- [ ] **Step 1: Write the failing tests**

Create `tests/ArticleExtractor/RecordingLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use Psr\Log\AbstractLogger;

/**
 * This test double records PSR-3 log calls so a test can see whether Readability received a logger.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * This value holds each log call as a level and a message, in order.
     *
     * @var list<array{0: mixed, 1: string}>
     */
    public array $records = [];

    /**
     * This method stores the log call and does not write it anywhere else.
     *
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message];
    }
}
```

Add these imports to `ArticleExtractorTest.php` (ordered imports: `fivefilters` before `PHPUnit` before `Yumo`):

```php
use fivefilters\Readability\ParseException;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorError;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorException;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
```

Append these methods to `ArticleExtractorTest`:

```php
    public function test_too_many_elements_throws_too_large(): void
    {
        $html = '<html><body>' . str_repeat('<span>x</span>', 40) . '</body></html>';
        $extractor = new ArticleExtractor(new ExtractPolicy(maxElemsToParse: 10));

        try {
            $extractor->extract($this->page($html));
            self::fail('Expected ArticleExtractorException');
        } catch (ArticleExtractorException $exception) {
            self::assertSame(ArticleExtractorError::TooLarge, $exception->error);
            self::assertSame(
                'HTML document exceeds the configured element limit',
                $exception->getMessage(),
            );
            self::assertInstanceOf(ParseException::class, $exception->getPrevious());
        }
    }

    public function test_zero_element_cap_parses_a_document_over_the_lowered_limit(): void
    {
        $html = '<html><body>' . str_repeat('<span>x</span>', 40) . '</body></html>';
        $extractor = new ArticleExtractor(new ExtractPolicy(maxElemsToParse: 0));

        $result = $extractor->extract($this->page($html));

        self::assertTrue($result->isOk());
    }

    public function test_relative_link_stays_relative_when_fix_is_off(): void
    {
        $extractor = new ArticleExtractor(new ExtractPolicy(fixRelativeURLs: false));
        $result = $extractor->extract($this->page($this->articleHtml()));

        self::assertTrue($result->isOk());
        self::assertNotNull($result->document);
        self::assertStringContainsString('href="/x"', $result->document->content);
        self::assertStringNotContainsString('https://ex.com/x', $result->document->content);
    }

    public function test_logger_is_not_called_when_debug_is_false(): void
    {
        $logger = new RecordingLogger();
        $extractor = new ArticleExtractor(new ExtractPolicy(debug: false), $logger);

        $extractor->extract($this->page($this->articleHtml()));

        self::assertSame([], $logger->records);
    }

    public function test_logger_receives_messages_when_debug_is_true(): void
    {
        $logger = new RecordingLogger();
        $extractor = new ArticleExtractor(new ExtractPolicy(debug: true), $logger);

        $extractor->extract($this->page($this->articleHtml()));

        self::assertNotEmpty($logger->records);
    }
```

`test_logger_receives_messages_when_debug_is_true` also makes Readability call `error_log()`, which prints `Reader: (Readability)` lines to stderr. That stderr is expected. It is not a test failure.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter 'test_too_many_elements_throws_too_large|test_zero_element_cap_parses_a_document_over_the_lowered_limit|test_relative_link_stays_relative_when_fix_is_off|test_logger_is_not_called_when_debug_is_false|test_logger_receives_messages_when_debug_is_true'`

Expected:

- `test_too_many_elements_throws_too_large` FAIL — no exception, because the cap is still hardcoded at 30000 and 40 spans are under that.
- `test_zero_element_cap_parses_a_document_over_the_lowered_limit` PASS already — 40 spans are under both 30000 and “unlimited”. Leave this test in place; it guards the policy value once the cap is forwarded. Do not weaken it.
- `test_relative_link_stays_relative_when_fix_is_off` FAIL — `href` is still `https://ex.com/x` because `fixRelativeURLs` is hardcoded true.
- `test_logger_is_not_called_when_debug_is_false` PASS already — the logger is not passed yet. Leave it; it fails closed if a later edit passes the logger while `debug` is false.
- `test_logger_receives_messages_when_debug_is_true` FAIL — `$logger->records` is empty.

- [ ] **Step 3: Write minimal implementation**

Replace `extract()` and `configurationFor()` with the following, and add the two private classifiers. Add `use fivefilters\Readability\ParseException;` to the imports.

```php
    /**
     * This method extracts an article from `$html`, or returns NoContent when the document is blank or has no article body.
     *
     * @throws ArticleExtractorException When the document exceeds the element cap or parsing fails unexpectedly
     */
    public function extract(Utf8Html $html): ExtractResult
    {
        if (trim($html->html) === '') {
            return ExtractResult::noContent($html->sourceUrl);
        }

        try {
            $article = (new Readability($this->configurationFor($html)))->parse($html->html);
        } catch (ParseException $exception) {
            if ($this->isEmptyInput($exception)) {
                return ExtractResult::noContent($html->sourceUrl);
            }
            if ($this->isTooLarge($exception)) {
                throw new ArticleExtractorException(ArticleExtractorError::TooLarge, previous: $exception);
            }

            throw new ArticleExtractorException(ArticleExtractorError::Unexpected, previous: $exception);
        } catch (\Throwable $exception) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected, previous: $exception);
        }

        $title = PlainText::fromUntrusted($article->title);
        $excerpt = PlainText::fromUntrustedNullable($article->excerpt);
        $siteName = PlainText::fromUntrustedNullable($article->siteName);

        if (!$article->hasContent()) {
            return ExtractResult::noContent($html->sourceUrl, $title, $excerpt, $siteName);
        }

        $content = $article->content;
        if (!is_string($content)) {
            throw new ArticleExtractorException(ArticleExtractorError::Unexpected);
        }

        return ExtractResult::ok(new ReadableDocument(
            title: $title,
            excerpt: $excerpt,
            siteName: $siteName,
            content: $content,
            sourceUrl: $html->sourceUrl,
        ));
    }

    /**
     * This method builds the vendor configuration for one page from the stored policy.
     *
     * The logger is included only when debug is on. Readability writes to a PSR-3
     * logger even when its own debug flag is false.
     */
    private function configurationFor(Utf8Html $html): Configuration
    {
        return new Configuration(
            debug: $this->policy->debug,
            logger: $this->policy->debug ? $this->logger : null,
            maxElemsToParse: $this->policy->maxElemsToParse,
            charThreshold: $this->policy->charThreshold,
            fixRelativeURLs: $this->policy->fixRelativeURLs,
            originalURL: $this->policy->fixRelativeURLs ? $html->sourceUrl : null,
        );
    }

    /**
     * This method reports whether `$exception` is Readability’s empty-input failure.
     *
     * v4.1.0 has no error code. The message is the one from `ParseException::emptyInput()`.
     */
    private function isEmptyInput(ParseException $exception): bool
    {
        return $exception->getMessage() === 'No HTML content provided.';
    }

    /**
     * This method reports whether `$exception` is Readability’s element-cap failure.
     *
     * v4.1.0 has no error code. The message prefix is the one from `ParseException::tooManyElements()`.
     */
    private function isTooLarge(ParseException $exception): bool
    {
        return str_starts_with($exception->getMessage(), 'Aborting parsing document;');
    }
```

Do not set `disableJSONLD` or any other `Configuration` property. Vendor defaults must stay.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter ArticleExtractorTest`

Expected: PASS, including the Task 6 and Task 7 cases. Stderr from the debug-true test may contain `Reader: (Readability)` lines.

- [ ] **Step 5: Run the full suite and the quality gates**

Run: `composer test`

Expected: PASS. UrlGuard, HttpFetcher, and EncodingNormalizer tests stay green.

Run:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both exit `0`. This is the same check `./bin/quality` runs inside Docker (`composer cs-check` + `composer phpstan`). Run `./bin/quality` instead once Docker is available again.

If cs-fixer reports diffs, run `vendor/bin/php-cs-fixer fix` then re-run both commands.

Typical PHPStan issues to fix immediately:

- `$article->content` is `?string`. Keep the `is_string($content)` check before passing it to `ReadableDocument`.
- `Configuration::$logger` accepts `?LoggerInterface`. Passing `$this->logger` only in the debug branch is valid.
- `getPrevious()` is `?Throwable`. `assertInstanceOf(ParseException::class, ...)` in the test is enough; do not call methods on `getPrevious()` without that assertion.

- [ ] **Step 6: Manual checklist against the spec**

Confirm each item:

1. `extract(Utf8Html): ExtractResult` exists.
2. Blank HTML and a document with no visible body text return `NoContent`, not an exception.
3. A long article returns `Ok`, the paragraph is in `content`, and the nav/footer text is not.
4. With `fixRelativeURLs` true (the default), `href="/x"` becomes `https://ex.com/x` using `Utf8Html::$sourceUrl`. With the flag false, the href stays `/x`.
5. `sourceUrl` on Ok and NoContent is `Utf8Html::$sourceUrl`.
6. Title, excerpt, and site name are `PlainText`. Tags are stripped. `html()` escapes `&`. Empty excerpt and site name are null. An empty title is `PlainText` with `raw() === ''`.
7. `maxElemsToParse: 10` on a 40-span document throws `ArticleExtractorException` with `ArticleExtractorError::TooLarge` and a `ParseException` previous. `maxElemsToParse: 0` does not throw on that document.
8. A logger injected with `debug: false` receives no calls. The same logger with `debug: true` receives calls.
9. `byline`, `publishedTime`, `image`, and vendor `Article` are not on `ReadableDocument` or `ExtractResult`.
10. No `HtmlSanitizer` or `Orchestrator` code was added. `src/index.php` is untouched.

- [ ] **Step 7: Commit**

```bash
git add src/ArticleExtractor/ArticleExtractor.php tests/ArticleExtractor/ArticleExtractorTest.php tests/ArticleExtractor/RecordingLogger.php
git commit -m "$(cat <<'EOF'
feat(article-extractor): map element-cap failures and debug logging

EOF
)"
```

If Step 5 required formatting or type fixes in other ArticleExtractor files, include those files in this commit. If nothing in `src/` or `tests/` changed after Step 4, still commit the Task 8 test files and the `extract()` / `configurationFor()` update from Step 3.

---

## Self-review (plan author)

**Spec coverage:**

| Spec area | Task |
|-----------|------|
| `ArticleExtractorError` + default messages | Task 1 |
| `ArticleExtractorException` hard failures only | Task 1, Task 8 |
| `PlainText::fromUntrusted` / `fromUntrustedNullable` / `raw` / `html` / `__toString` | Task 2 |
| `ExtractPolicy` defaults and non-negative limits | Task 3 |
| `ReadableDocument` fields, content left unsanitized | Task 4, Task 6 |
| `ExtractStatus` + `ExtractResult` Ok / NoContent invariants | Task 5 |
| `extract(Utf8Html): ExtractResult` | Task 6 |
| Blank HTML short-circuit → NoContent | Task 6 |
| Relative URL rewrite via `originalURL = sourceUrl` | Task 6, Task 8 |
| Chrome dropped from a long article | Task 6 |
| Fused title / excerpt / siteName, no separate OG parser | Task 6, Task 7 |
| `hasContent() === false` → NoContent with metadata | Task 7 |
| Tag stripping on metadata (`Hi & Bye`) | Task 7 |
| `ParseException::tooManyElements` → `TooLarge` | Task 8 |
| `maxElemsToParse = 0` unlimited | Task 8 |
| `fixRelativeURLs = false` leaves relative href | Task 8 |
| Logger passed only when `debug` is true | Task 8 |
| `ParseException::emptyInput` mapped to NoContent | Task 8 (catch); Task 6 (short-circuit that makes the catch unreachable today) |
| Other throwables → `Unexpected` | Task 8 catch; no HTML fixture produces one on v4.1.0 |
| MVP fields not mapped (`byline`, `image`, …) | Class shape in Tasks 4–5; checklist in Task 8 |
| Out of scope (fetch, encoding, sanitizer, orchestrator, JS) | Global Constraints |

**Placeholder scan:** no TBD / TODO implementation steps. The Task 6 `Unexpected` throw on `!hasContent()` is real code that Task 7 replaces; the plan shows the replacement method in full. Vendor messages are the v4.1.0 strings, not pseudocode.

**Type consistency:** `extract(Utf8Html $html): ExtractResult` is stable from Task 6. `ExtractResult::noContent(string $sourceUrl, ?PlainText $title = null, ?PlainText $excerpt = null, ?PlainText $siteName = null)` matches Task 5 and Task 7. `ReadableDocument` constructor argument order matches Task 4 and every later call. `ArticleExtractorException` argument order is `($error, $message = '', $previous = null)`; Task 8 uses `previous:` as a named argument. `configurationFor(Utf8Html $html): Configuration` exists from Task 6; Task 8 replaces its body and does not rename it.

**Deviations from the spec’s literal sketches (required for this codebase):**

- Negative policy limits throw `\InvalidArgumentException`, the same style as `FetchPolicy`. `assert()` is not used, because `zend.assertions` can disable it.
- `ArticleExtractor` stores the policy with `$policy ?? new ExtractPolicy()` inside the constructor, matching `HttpFetcher`, instead of a `new` default on the parameter.
- Suggested case “nav-only short page → NoContent” is not what v4.1.0 does when the nav has visible text. The plan’s NoContent fixture is an empty body. The article fixture is long enough to clear `charThreshold` 500 so the nav is actually excluded.
- `ParseException` classification uses the factory messages because the class has no error code.
- No parser port. `Unexpected` is implemented and not fixture-tested.
