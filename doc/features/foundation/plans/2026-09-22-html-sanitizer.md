# HtmlSanitizer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Phase 5 HtmlSanitizer so a `ReadableDocument` becomes a `SafeDocument` whose article HTML was purified under the MVP URI-scheme policy, or throw `HtmlSanitizerException` when Purifier cannot be configured.

**Architecture:** `HtmlSanitizer` is a thin adapter over `HTMLPurifier` (lockfile v4.19.1). It builds an `HTMLPurifier_Config` from `PurifyPolicy` plus a definition-cache directory, purifies only `ReadableDocument::$content`, and copies `PlainText` metadata and `sourceUrl` onto `SafeDocument`. Vendor types and vendor throwables stay inside the class. Stripping dangerous markup is success, including an empty fragment. `PurifyPolicy` rejects any scheme outside `http`, `https`, and `mailto` at construction.

**Tech Stack:** PHP `^8.5`, PHPUnit `^11`, `ezyang/htmlpurifier` `^4.19` (lockfile v4.19.1), PSR-4 `Yumo\LogRead\` → `src/`.

**Spec:** `doc/features/foundation/specs/HtmlSanitizer.md` (also `doc/features/foundation/feature.md` Phase 5)

## Global Constraints

- PHP `^8.5`. Namespace root: `Yumo\LogRead\` → `src/`; tests: `Yumo\LogRead\Tests\` → `tests/`.
- Documentation, identifiers, commit messages, and PHPDoc in **English**. Follow `.cursor/rules/php-docs.mdc`: complete sentences; do **not** describe types as “the fifth pipeline stage”.
- **Prerequisite:** `Yumo\LogRead\ArticleExtractor\PlainText` and `Yumo\LogRead\ArticleExtractor\ReadableDocument` must already exist, as specified in `doc/features/foundation/plans/2026-09-22-article-extractor.md`. Do not reimplement them. If either file is missing, stop and finish that plan first.
- **Parse, don’t validate:** `purify()` returns `SafeDocument`. The safety invariant is “`$html` was purified under this policy”, not a boolean. Do not add an `EncodingOutcome`-style soft status.
- Input is `ReadableDocument` only. Do not accept `string` or `Utf8Html` on `purify()`.
- Only `$document->content` is passed to `HTMLPurifier::purify()`. Copy `title`, `excerpt`, and `siteName` as the same `PlainText` instances. Do not purify, strip, or escape them again.
- `PurifyPolicy` defaults: `allowedSchemes = ['http', 'https', 'mailto']`, `debug = false`. Construction throws `\InvalidArgumentException` (not `assert()`, and not `HtmlSanitizerException`) when the list is empty, a token is not a lowercase letter-digit-hyphen scheme name, or a token is outside that three-scheme allowlist. `javascript`, `data`, `file`, and `ftp` are rejected here.
- Fixed inside the adapter, not on the policy: `Core.Encoding = UTF-8`, `HTML.Doctype = XHTML 1.0 Transitional`, `HTML.Trusted = false`. Do not set `HTML.Allowed`.
- The definition-cache path is the `HtmlSanitizer` constructor argument only. `null` means `sys_get_temp_dir() . '/log-read-htmlpurifier'`. `PurifyPolicy` has no cache property. Do not add `PipelineOptions` or `OrchestratorFactory` in this plan.
- When `debug` is false, create the cache directory if it is missing. If the path is not a writable directory after that attempt, throw `HtmlSanitizerException` with `HtmlSanitizerError::Configuration`. When `debug` is true, set `Cache.DefinitionImpl` to `null` and `Core.CollectErrors` to `true`, and do not require the cache directory. Debug must not change the purified HTML of a given fragment.
- Do not add a logger parameter. `Core.CollectErrors` stays inside the adapter: do not put collector messages on `SafeDocument`, and do not throw because the collector reported a stripped tag.
- An empty or whitespace-only purified fragment is still a `SafeDocument`. Do not throw, and do not trim `$html`. Orchestrator’s `SanitizedEmpty` mapping is out of scope.
- `HtmlSanitizerException` is only for hard failures: `Configuration` (cache path or `HTMLPurifier_Exception`) and `Unexpected` (any other throwable). Ordinary dirty HTML does not throw.
- Unit tests build `ReadableDocument` fixtures. No HTTP.
- **Vendor behavior this plan is written against (HTMLPurifier v4.19.1):** with the config above, `<p>x</p><script>alert(1)</script>` becomes `<p>x</p>`; `href="javascript:…"`, `href="ftp:…"`, and `href="file:…"` drop the attribute and keep the anchor text (`<a>x</a>`); `mailto` and `https` hrefs are kept; `<img src="https://…">` is serialized as an XHTML empty element with a space before `/>`; a `data:` image is removed entirely; `<iframe>` is removed; `onclick` is removed; a whitespace-only string is returned unchanged; a script-only fragment becomes `''`. A missing cache directory makes Purifier emit `E_USER_WARNING` and still return HTML — it does not throw and it does not create the directory. This adapter must create the directory or throw `Configuration` before `purify()` runs. `URI.AllowedSchemes` is a lookup (`scheme => true`), not a list.
- **Environment note for this plan's execution:** Docker is unavailable in the current environment, so every command below runs PHP/Composer directly on the host (`vendor/bin/phpunit`, `composer test`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`) instead of the `./bin/*` Docker wrappers. When Docker is available again, `./bin/quality` must still pass on the final code — the commands are equivalent, only the execution wrapper differs.
- Do not implement `Orchestrator`. Leave `src/index.php` and `bin/run` untouched.

---

## File Structure

| Path | Responsibility |
|------|----------------|
| `src/HtmlSanitizer/HtmlSanitizerError.php` | Hard-failure kind (`Configuration`, `Unexpected`) + `defaultMessage()` |
| `src/HtmlSanitizer/HtmlSanitizerException.php` | Hard failure only; carries `HtmlSanitizerError` and an optional previous throwable |
| `src/HtmlSanitizer/PurifyPolicy.php` | Immutable scheme list and debug flag; rejects anything outside the MVP allowlist |
| `src/HtmlSanitizer/SafeDocument.php` | Purified UTF-8 HTML fragment plus copied `PlainText` metadata and `sourceUrl` |
| `src/HtmlSanitizer/HtmlSanitizer.php` | Adapter: Purifier config, cache directory, purify article HTML, copy metadata |
| `tests/HtmlSanitizer/HtmlSanitizerExceptionTest.php` | Default vs custom message, `$previous` |
| `tests/HtmlSanitizer/PurifyPolicyTest.php` | Defaults and rejection of empty, malformed, and disallowed schemes |
| `tests/HtmlSanitizer/SafeDocumentTest.php` | Field storage |
| `tests/HtmlSanitizer/HtmlSanitizerTest.php` | Stripping, allowed URLs, metadata identity, empty fragment, cache, debug |

---

### Task 1: `HtmlSanitizerError` + `HtmlSanitizerException`

**Files:**
- Create: `src/HtmlSanitizer/HtmlSanitizerError.php`
- Create: `src/HtmlSanitizer/HtmlSanitizerException.php`
- Test: `tests/HtmlSanitizer/HtmlSanitizerExceptionTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `enum HtmlSanitizerError { case Configuration; case Unexpected; public function defaultMessage(): string }`
  - `final class HtmlSanitizerException extends \RuntimeException { public function __construct(public readonly HtmlSanitizerError $error, string $message = '', ?\Throwable $previous = null) }`
  - Default messages (verbatim from the spec):
    - `Configuration` → `HTML sanitizer configuration or runtime setup failed`
    - `Unexpected` → `HTML sanitization failed unexpectedly`

- [ ] **Step 1: Write the failing test**

Create `tests/HtmlSanitizer/HtmlSanitizerExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerError;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;

final class HtmlSanitizerExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(HtmlSanitizerError $error, string $expected): void
    {
        $exception = new HtmlSanitizerException($error);

        self::assertSame($expected, $exception->getMessage());
        self::assertSame($error, $exception->error);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @return array<string, array{HtmlSanitizerError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'configuration' => [
                HtmlSanitizerError::Configuration,
                'HTML sanitizer configuration or runtime setup failed',
            ],
            'unexpected' => [
                HtmlSanitizerError::Unexpected,
                'HTML sanitization failed unexpectedly',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $exception = new HtmlSanitizerException(
            HtmlSanitizerError::Configuration,
            'cache directory is not usable',
            $previous,
        );

        self::assertSame('cache directory is not usable', $exception->getMessage());
        self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
        self::assertSame($previous, $exception->getPrevious());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter HtmlSanitizerExceptionTest`

Expected: FAIL with class not found (`HtmlSanitizerException` or `HtmlSanitizerError`).

- [ ] **Step 3: Write minimal implementation**

Create `src/HtmlSanitizer/HtmlSanitizerError.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This enum names hard failures that stop HTML purification for one article.
 *
 * A fragment that loses a script, an iframe, or a disallowed URL is not one of
 * these cases. Purification still returns a `SafeDocument`, even when the
 * fragment is empty.
 */
enum HtmlSanitizerError
{
    /** This case applies when the purifier config or the definition-cache directory cannot be used. */
    case Configuration;

    /** This case applies when the adapter catches a throwable that is not a purifier configuration failure. */
    case Unexpected;

    /**
     * This method returns the standard message for this failure when the call site does not provide a more specific one.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Configuration => 'HTML sanitizer configuration or runtime setup failed',
            self::Unexpected => 'HTML sanitization failed unexpectedly',
        };
    }
}
```

Create `src/HtmlSanitizer/HtmlSanitizerException.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This exception reports that HTML purification aborted for one article.
 *
 * Inspect `$error` to tell a configuration or cache failure from an unexpected
 * failure. Dirty markup does not throw: tags and URLs are stripped and a
 * `SafeDocument` is returned.
 */
final class HtmlSanitizerException extends \RuntimeException
{
    /**
     * This constructor creates an exception for the specified purification failure.
     */
    public function __construct(
        /** This value identifies why purification aborted. */
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

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter HtmlSanitizerExceptionTest`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/HtmlSanitizer/HtmlSanitizerError.php src/HtmlSanitizer/HtmlSanitizerException.php tests/HtmlSanitizer/HtmlSanitizerExceptionTest.php
git commit -m "$(cat <<'EOF'
feat(html-sanitizer): add hard-failure error types

EOF
)"
```

---

### Task 2: `PurifyPolicy`

**Files:**
- Create: `src/HtmlSanitizer/PurifyPolicy.php`
- Test: `tests/HtmlSanitizer/PurifyPolicyTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `final readonly class PurifyPolicy`
  - `public function __construct(public array $allowedSchemes = ['http', 'https', 'mailto'], public bool $debug = false)`
  - `@param list<string> $allowedSchemes`
  - Throws `\InvalidArgumentException` `allowedSchemes must not be empty` when the list is `[]`
  - Throws `\InvalidArgumentException` `allowedSchemes must be lowercase scheme names` when a token is not a string matching `^[a-z][a-z0-9-]*$` (letter, then letters, digits, or hyphens)
  - Throws `\InvalidArgumentException` `allowedSchemes only allows http, https, and mailto` when a well-formed token is anything else, including `javascript`, `data`, `file`, and `ftp`
  - A non-empty subset of the three allowed names is valid and is stored unchanged, including order
  - No cache-path property

- [ ] **Step 1: Write the failing test**

Create `tests/HtmlSanitizer/PurifyPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;

final class PurifyPolicyTest extends TestCase
{
    public function test_defaults_are_the_mvp_allowlist_and_debug_off(): void
    {
        $policy = new PurifyPolicy();

        self::assertSame(['http', 'https', 'mailto'], $policy->allowedSchemes);
        self::assertFalse($policy->debug);
    }

    public function test_subset_and_reordered_allowlist_is_stored_as_given(): void
    {
        $policy = new PurifyPolicy(['mailto', 'https'], true);

        self::assertSame(['mailto', 'https'], $policy->allowedSchemes);
        self::assertTrue($policy->debug);
    }

    #[DataProvider('rejectedSchemes')]
    public function test_rejects_schemes_outside_the_mvp_allowlist(array $schemes, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PurifyPolicy($schemes);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rejectedSchemes(): array
    {
        return [
            'empty list' => [[], 'allowedSchemes must not be empty'],
            'javascript' => [['javascript'], 'allowedSchemes only allows http, https, and mailto'],
            'data' => [['data'], 'allowedSchemes only allows http, https, and mailto'],
            'file' => [['file'], 'allowedSchemes only allows http, https, and mailto'],
            'ftp' => [['ftp'], 'allowedSchemes only allows http, https, and mailto'],
            'allowed plus ftp' => [['http', 'ftp'], 'allowedSchemes only allows http, https, and mailto'],
            'uppercase' => [['HTTP'], 'allowedSchemes must be lowercase scheme names'],
            'with colon' => [['http:'], 'allowedSchemes must be lowercase scheme names'],
            'blank token' => [[''], 'allowedSchemes must be lowercase scheme names'],
            'embedded space' => [['http '], 'allowedSchemes must be lowercase scheme names'],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter PurifyPolicyTest`

Expected: FAIL with class not found (`PurifyPolicy`).

- [ ] **Step 3: Write minimal implementation**

Create `src/HtmlSanitizer/PurifyPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This class holds the URI schemes and the debug switch for one HTML purifier.
 *
 * Encoding, doctype, and the definition-cache directory are not fields here.
 * The purifier always reads UTF-8, and the cache path is chosen by
 * `HtmlSanitizer`.
 *
 * `$policy = new PurifyPolicy();`
 */
final readonly class PurifyPolicy
{
    /** Schemes an article URL may use. Anything else is rejected at construction. */
    private const array MVP_SCHEMES = [
        'http' => true,
        'https' => true,
        'mailto' => true,
    ];

    /**
     * This constructor stores the scheme list and the debug switch.
     *
     * @param list<string> $allowedSchemes Lowercase scheme names. Only `http`, `https`, and `mailto` are accepted.
     */
    public function __construct(
        /** This value is the URI scheme allowlist copied onto `URI.AllowedSchemes`. */
        public array $allowedSchemes = ['http', 'https', 'mailto'],
        /** This value turns off the definition cache and enables the purifier error collector. */
        public bool $debug = false,
    ) {
        if ($allowedSchemes === []) {
            throw new \InvalidArgumentException('allowedSchemes must not be empty');
        }

        foreach ($allowedSchemes as $scheme) {
            if (!is_string($scheme) || preg_match('/^[a-z][a-z0-9-]*$/', $scheme) !== 1) {
                throw new \InvalidArgumentException('allowedSchemes must be lowercase scheme names');
            }
            if (!isset(self::MVP_SCHEMES[$scheme])) {
                throw new \InvalidArgumentException('allowedSchemes only allows http, https, and mailto');
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter PurifyPolicyTest`

Expected: PASS (12 tests: 2 single tests + 10 provider rows).

- [ ] **Step 5: Commit**

```bash
git add src/HtmlSanitizer/PurifyPolicy.php tests/HtmlSanitizer/PurifyPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(html-sanitizer): reject schemes outside the MVP allowlist

EOF
)"
```

---

### Task 3: `SafeDocument`

**Files:**
- Create: `src/HtmlSanitizer/SafeDocument.php`
- Test: `tests/HtmlSanitizer/SafeDocumentTest.php`

**Interfaces:**
- Consumes: `Yumo\LogRead\ArticleExtractor\PlainText` (`fromUntrusted()`, `raw()`)
- Produces:
  - `final readonly class SafeDocument`
  - `public function __construct(public string $html, public PlainText $title, public ?PlainText $excerpt, public ?PlainText $siteName, public string $sourceUrl)`
  - The constructor is public, matching `ReadableDocument`. Production code obtains instances from `HtmlSanitizer::purify()`; this task does not make the constructor private.
  - No validation inside the constructor. Empty `$html` is legal.

- [ ] **Step 1: Confirm the ArticleExtractor types exist**

Run: `test -f src/ArticleExtractor/PlainText.php && test -f src/ArticleExtractor/ReadableDocument.php`

Expected: exit 0. If either file is missing, stop. Finish `doc/features/foundation/plans/2026-09-22-article-extractor.md` through `PlainText` and `ReadableDocument` before continuing. Do not copy those classes into `HtmlSanitizer`.

- [ ] **Step 2: Write the failing test**

Create `tests/HtmlSanitizer/SafeDocumentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;

final class SafeDocumentTest extends TestCase
{
    public function test_stores_purified_html_and_metadata(): void
    {
        $title = PlainText::fromUntrusted('Tom & Jerry');
        $excerpt = PlainText::fromUntrusted('A short line');
        $siteName = PlainText::fromUntrusted('Example');

        $document = new SafeDocument(
            '<p>x</p>',
            $title,
            $excerpt,
            $siteName,
            'https://example.com/article',
        );

        self::assertSame('<p>x</p>', $document->html);
        self::assertSame($title, $document->title);
        self::assertSame('Tom & Jerry', $document->title->raw());
        self::assertSame($excerpt, $document->excerpt);
        self::assertSame($siteName, $document->siteName);
        self::assertSame('https://example.com/article', $document->sourceUrl);
    }

    public function test_allows_empty_html_and_missing_optional_metadata(): void
    {
        $document = new SafeDocument('', PlainText::fromUntrusted(''), null, null, 'https://example.com/a');

        self::assertSame('', $document->html);
        self::assertSame('', $document->title->raw());
        self::assertNull($document->excerpt);
        self::assertNull($document->siteName);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test -- --filter SafeDocumentTest`

Expected: FAIL with class not found (`SafeDocument`).

- [ ] **Step 4: Write minimal implementation**

Create `src/HtmlSanitizer/SafeDocument.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

use Yumo\LogRead\ArticleExtractor\PlainText;

/**
 * This class holds article HTML that has been purified, plus the metadata that belongs with it.
 *
 * `$html` is a UTF-8 fragment. Emit it as HTML. Do not run `htmlspecialchars` on the whole fragment.
 * `$title`, `$excerpt`, and `$siteName` are plain text copied from the extracted article:
 * store `raw()`, and use `html()` when rendering them as text.
 */
final readonly class SafeDocument
{
    /**
     * This constructor stores the purified fragment and its metadata.
     */
    public function __construct(
        /** This value holds the purified article HTML. It may be empty when every element was stripped. */
        public string $html,
        /** This value holds the article title copied from the extracted document. */
        public PlainText $title,
        /** This value holds the excerpt copied from the extracted document, or null when there was none. */
        public ?PlainText $excerpt,
        /** This value holds the site name copied from the extracted document, or null when there was none. */
        public ?PlainText $siteName,
        /** This value holds the page URL the article was fetched from. */
        public string $sourceUrl,
    ) {
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter SafeDocumentTest`

Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/HtmlSanitizer/SafeDocument.php tests/HtmlSanitizer/SafeDocumentTest.php
git commit -m "$(cat <<'EOF'
feat(html-sanitizer): add SafeDocument for purified article HTML

EOF
)"
```

---

### Task 4: `HtmlSanitizer::purify()` strips unsafe markup and copies metadata

**Files:**
- Create: `src/HtmlSanitizer/HtmlSanitizer.php`
- Create: `tests/HtmlSanitizer/HtmlSanitizerTest.php`

**Interfaces:**
- Consumes:
  - `PurifyPolicy` from Task 2
  - `SafeDocument` from Task 3
  - `HtmlSanitizerException` and `HtmlSanitizerError` from Task 1
  - `ReadableDocument` and `PlainText` from ArticleExtractor
  - `HTMLPurifier`, `HTMLPurifier_Config`, `HTMLPurifier_Exception` (global classes from `ezyang/htmlpurifier`)
- Produces:
  - `final class HtmlSanitizer`
  - `public function __construct(?PurifyPolicy $policy = null, ?string $definitionCachePath = null)`
  - `null` policy becomes `new PurifyPolicy()` inside the constructor (not a `new` default on the parameter)
  - `null` cache path becomes `sys_get_temp_dir() . '/log-read-htmlpurifier'`
  - `public function purify(ReadableDocument $document): SafeDocument`
  - `@throws HtmlSanitizerException`
  - This task’s `config()` always sets `Cache.SerializerPath` to the resolved path. It does not create the directory and it does not branch on `debug`. Tests pass a directory they create first. Task 5 replaces `config()`.

- [ ] **Step 1: Write the failing test**

Create `tests/HtmlSanitizer/HtmlSanitizerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;

final class HtmlSanitizerTest extends TestCase
{
    /** This value is a writable directory passed as the definition-cache path. */
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/log-read-htmlpurifier-' . bin2hex(random_bytes(4));
        mkdir($this->cachePath, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cachePath);
    }

    #[DataProvider('markup')]
    public function test_purify_keeps_article_markup_and_drops_unsafe_constructs(string $content, string $expected): void
    {
        $safe = $this->sanitizer()->purify($this->article($content));

        self::assertInstanceOf(SafeDocument::class, $safe);
        self::assertSame($expected, $safe->html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markup(): array
    {
        return [
            'script' => ['<p>x</p><script>alert(1)</script>', '<p>x</p>'],
            'javascript url' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'https link' => ['<a href="https://example.com/a">x</a>', '<a href="https://example.com/a">x</a>'],
            'mailto' => ['<a href="mailto:a@b.c">x</a>', '<a href="mailto:a@b.c">x</a>'],
            'ftp' => ['<a href="ftp://example.com/f">x</a>', '<a>x</a>'],
            'file url' => ['<a href="file:///etc/passwd">x</a>', '<a>x</a>'],
            'https image' => [
                '<img src="https://example.com/x.png" alt="x">',
                '<img src="https://example.com/x.png" alt="x" />',
            ],
            'iframe' => ['<p>ok</p><iframe src="https://example.com"></iframe>', '<p>ok</p>'],
            'data image' => ['<img src="data:image/png;base64,aaa" alt="x">', ''],
            'onclick' => ['<p onclick="alert(1)">x</p>', '<p>x</p>'],
        ];
    }

    public function test_https_only_policy_strips_mailto_and_keeps_https(): void
    {
        $sanitizer = new HtmlSanitizer(new PurifyPolicy(['https']), $this->cachePath);
        $safe = $sanitizer->purify($this->article(
            '<a href="mailto:a@b.c">x</a><a href="https://example.com/a">y</a>',
        ));

        self::assertSame('<a>x</a><a href="https://example.com/a">y</a>', $safe->html);
    }

    public function test_copies_metadata_instances_and_source_url(): void
    {
        $document = $this->article('<p>x</p><script>alert(1)</script>');
        $safe = $this->sanitizer()->purify($document);

        self::assertSame('<p>x</p>', $safe->html);
        self::assertSame($document->title, $safe->title);
        self::assertSame('Tom & Jerry', $safe->title->raw());
        self::assertSame($document->excerpt, $safe->excerpt);
        self::assertSame($document->siteName, $safe->siteName);
        self::assertSame('https://example.com/article', $safe->sourceUrl);
    }

    public function test_copies_null_excerpt_and_site_name(): void
    {
        $document = new ReadableDocument(
            PlainText::fromUntrusted('Title'),
            null,
            null,
            '<p>x</p>',
            'https://example.com/a',
        );
        $safe = $this->sanitizer()->purify($document);

        self::assertSame($document->title, $safe->title);
        self::assertNull($safe->excerpt);
        self::assertNull($safe->siteName);
        self::assertSame('https://example.com/a', $safe->sourceUrl);
    }

    public function test_whitespace_only_fragment_is_returned_unchanged(): void
    {
        $safe = $this->sanitizer()->purify($this->article('   '));

        self::assertSame('   ', $safe->html);
    }

    public function test_script_only_fragment_is_an_empty_safe_document(): void
    {
        $safe = $this->sanitizer()->purify($this->article('<script>alert(1)</script>'));

        self::assertSame('', $safe->html);
    }

    /**
     * This method builds a sanitizer that writes definitions into the per-test cache directory.
     */
    private function sanitizer(): HtmlSanitizer
    {
        return new HtmlSanitizer(new PurifyPolicy(), $this->cachePath);
    }

    /**
     * This method builds an extracted article whose title still contains an ampersand after tags are stripped.
     *
     * The title starts as `Tom & <b>Jerry</b>`. `PlainText` stores `Tom & Jerry`.
     * Purification must keep that same instance. Re-parsing the title as HTML would change the ampersand.
     */
    private function article(string $content): ReadableDocument
    {
        return new ReadableDocument(
            PlainText::fromUntrusted('Tom & <b>Jerry</b>'),
            PlainText::fromUntrustedNullable('Short & sweet'),
            PlainText::fromUntrustedNullable('Example News'),
            $content,
            'https://example.com/article',
        );
    }

    /**
     * This method deletes a cache directory created for one test, including files Purifier wrote inside it.
     */
    private function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter HtmlSanitizerTest`

Expected: FAIL with class not found (`HtmlSanitizer`).

- [ ] **Step 3: Write minimal implementation**

Create `src/HtmlSanitizer/HtmlSanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_Exception;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

/**
 * This class purifies extracted article HTML and returns a document that is safe to store or emit as HTML.
 *
 * Only the article fragment is purified. Title, excerpt, and site name are copied through unchanged.
 * Scripts, iframes, event handlers, and URLs whose scheme is not allowed are stripped. That is a
 * successful result, including when nothing remains.
 *
 * `$safe = (new HtmlSanitizer())->purify($readableDocument);`
 */
final class HtmlSanitizer
{
    /** This value holds the scheme allowlist and the debug switch. */
    private PurifyPolicy $policy;

    /** This value is the directory where Purifier writes its definition cache when debug is off. */
    private string $definitionCachePath;

    /**
     * This constructor stores the policy and the definition-cache directory.
     *
     * When `$policy` is omitted, the MVP allowlist is used and debug is off.
     * When `$definitionCachePath` is omitted, definitions are stored under the system temp directory.
     */
    public function __construct(
        ?PurifyPolicy $policy = null,
        ?string $definitionCachePath = null,
    ) {
        $this->policy = $policy ?? new PurifyPolicy();
        $this->definitionCachePath = $definitionCachePath ?? sys_get_temp_dir() . '/log-read-htmlpurifier';
    }

    /**
     * This method purifies `$document->content` and copies the document’s metadata onto the result.
     *
     * @throws HtmlSanitizerException When the purifier config cannot be built or purification throws
     */
    public function purify(ReadableDocument $document): SafeDocument
    {
        $config = $this->config();

        try {
            $html = (new HTMLPurifier($config))->purify($document->content);
        } catch (HTMLPurifier_Exception $exception) {
            throw new HtmlSanitizerException(HtmlSanitizerError::Configuration, '', $exception);
        } catch (\Throwable $exception) {
            throw new HtmlSanitizerException(HtmlSanitizerError::Unexpected, '', $exception);
        }

        return new SafeDocument(
            $html,
            $document->title,
            $document->excerpt,
            $document->siteName,
            $document->sourceUrl,
        );
    }

    /**
     * This method builds the purifier config: UTF-8, a fixed XHTML doctype, and the policy’s scheme lookup.
     */
    private function config(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.Trusted', false);
        $config->set('URI.AllowedSchemes', $this->schemeLookup());
        $config->set('Cache.SerializerPath', $this->definitionCachePath);

        return $config;
    }

    /**
     * This method turns the policy’s scheme list into the lookup map Purifier expects.
     *
     * @return array<string, true>
     */
    private function schemeLookup(): array
    {
        $lookup = [];
        foreach ($this->policy->allowedSchemes as $scheme) {
            $lookup[$scheme] = true;
        }

        return $lookup;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter HtmlSanitizerTest`

Expected: PASS (15 tests: 10 markup rows + 5 single tests).

- [ ] **Step 5: Commit**

```bash
git add src/HtmlSanitizer/HtmlSanitizer.php tests/HtmlSanitizer/HtmlSanitizerTest.php
git commit -m "$(cat <<'EOF'
feat(html-sanitizer): strip unsafe markup and copy metadata

EOF
)"
```

---

### Task 5: Definition cache and debug

**Files:**
- Modify: `src/HtmlSanitizer/HtmlSanitizer.php` (`config()`, add `prepareDefinitionCache()`)
- Modify: `tests/HtmlSanitizer/HtmlSanitizerTest.php` (append tests; keep the existing ones)

**Interfaces:**
- Consumes: `HtmlSanitizer::purify(ReadableDocument $document): SafeDocument` from Task 4
- Produces: the same public signatures. Behavior added:
  - `debug === false`: `prepareDefinitionCache()` runs before `Cache.SerializerPath` is set. A missing directory is created with mode `0775`. A path that is empty, a file, or a directory that is not writable throws `HtmlSanitizerException` with `HtmlSanitizerError::Configuration`, message `HTML purifier definition cache path is not usable`, and `$previous === null`.
  - `debug === true`: `Cache.DefinitionImpl` is `null`, `Core.CollectErrors` is `true`, and `prepareDefinitionCache()` is not called. A file path does not throw. The purified HTML for the script fixture matches the non-debug result `<p>x</p>`.
  - `purify()` from Task 4 stays as written. Cache failures are thrown from `config()`, outside the vendor `try`.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/HtmlSanitizer/HtmlSanitizerTest.php`, before the private helpers:

```php
public function test_missing_cache_directory_is_created(): void
{
    $path = sys_get_temp_dir() . '/log-read-htmlpurifier-missing-' . bin2hex(random_bytes(4));
    self::assertDirectoryDoesNotExist($path);

    try {
        $safe = (new HtmlSanitizer(new PurifyPolicy(), $path))->purify($this->article('<p>x</p>'));

        self::assertSame('<p>x</p>', $safe->html);
        self::assertDirectoryExists($path);
    } finally {
        $this->removeTree($path);
    }
}

public function test_file_cache_path_throws_configuration(): void
{
    $path = tempnam(sys_get_temp_dir(), 'lr-hp-');
    self::assertIsString($path);

    try {
        (new HtmlSanitizer(new PurifyPolicy(), $path))->purify($this->article('<p>x</p>'));
        self::fail('A file path must not be used as the definition cache');
    } catch (HtmlSanitizerException $exception) {
        self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
        self::assertSame('HTML purifier definition cache path is not usable', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    } finally {
        $this->removeTree($path);
    }
}

public function test_unwritable_cache_directory_throws_configuration(): void
{
    $path = sys_get_temp_dir() . '/log-read-htmlpurifier-ro-' . bin2hex(random_bytes(4));
    mkdir($path, 0775, true);
    chmod($path, 0555);
    if (is_writable($path)) {
        $this->removeTree($path);
        self::markTestSkipped('This process can still write to a mode 0555 directory');
    }

    try {
        (new HtmlSanitizer(new PurifyPolicy(), $path))->purify($this->article('<p>x</p>'));
        self::fail('An unwritable directory must not be used as the definition cache');
    } catch (HtmlSanitizerException $exception) {
        self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
        self::assertSame('HTML purifier definition cache path is not usable', $exception->getMessage());
    } finally {
        chmod($path, 0775);
        $this->removeTree($path);
    }
}

public function test_debug_does_not_require_a_cache_directory_and_strips_the_same_way(): void
{
    $file = tempnam(sys_get_temp_dir(), 'lr-hp-debug-');
    self::assertIsString($file);
    $content = '<p>x</p><script>alert(1)</script>';

    try {
        $debugged = (new HtmlSanitizer(new PurifyPolicy(debug: true), $file))->purify($this->article($content));
        $cached = $this->sanitizer()->purify($this->article($content));

        self::assertSame('<p>x</p>', $debugged->html);
        self::assertSame($cached->html, $debugged->html);
        self::assertFileExists($file);
    } finally {
        $this->removeTree($file);
    }
}
```

Add the missing import at the top of the test file:

```php
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerError;
```

Keep imports ordered alphabetically: `HtmlSanitizer`, `HtmlSanitizerError`, `HtmlSanitizerException`, `PurifyPolicy`, `SafeDocument`.

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `composer test -- --filter 'HtmlSanitizerTest::test_missing_cache_directory_is_created|HtmlSanitizerTest::test_file_cache_path_throws_configuration|HtmlSanitizerTest::test_unwritable_cache_directory_throws_configuration|HtmlSanitizerTest::test_debug_does_not_require_a_cache_directory_and_strips_the_same_way'`

Expected: FAIL.

- `test_missing_cache_directory_is_created` fails because the directory is not created (Purifier warns and still returns `<p>x</p>`, or PHPUnit surfaces the warning). `assertDirectoryExists` fails.
- `test_file_cache_path_throws_configuration` fails because no `HtmlSanitizerException` is thrown (`self::fail` or a warning).
- `test_unwritable_cache_directory_throws_configuration` fails the same way when the skip does not apply.
- `test_debug_does_not_require_a_cache_directory_and_strips_the_same_way` fails because debug still sets `Cache.SerializerPath` on a file and does not return a clean `SafeDocument`.

The Task 4 tests must still pass. If a new test errors on a missing import, add the imports and re-run before editing `HtmlSanitizer.php`.

- [ ] **Step 3: Replace `config()` and add `prepareDefinitionCache()`**

In `src/HtmlSanitizer/HtmlSanitizer.php`, replace `config()` with:

```php
/**
 * This method builds the purifier config for the stored policy.
 *
 * Debug skips the definition cache and collects errors inside the purifier.
 * Those messages are not returned and do not change the fragment.
 */
private function config(): HTMLPurifier_Config
{
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Core.Encoding', 'UTF-8');
    $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
    $config->set('HTML.Trusted', false);
    $config->set('URI.AllowedSchemes', $this->schemeLookup());

    if ($this->policy->debug) {
        $config->set('Cache.DefinitionImpl', null);
        $config->set('Core.CollectErrors', true);

        return $config;
    }

    $this->prepareDefinitionCache();
    $config->set('Cache.SerializerPath', $this->definitionCachePath);

    return $config;
}

/**
 * This method creates the definition-cache directory when it is missing.
 *
 * Purifier only warns when the directory is absent, and still returns HTML.
 * Callers need a hard failure instead, so this method throws before `purify()`.
 */
private function prepareDefinitionCache(): void
{
    $path = $this->definitionCachePath;
    if ($path === '' || (file_exists($path) && !is_dir($path)) || (is_dir($path) && !is_writable($path))) {
        throw new HtmlSanitizerException(
            HtmlSanitizerError::Configuration,
            'HTML purifier definition cache path is not usable',
        );
    }
    if (is_dir($path)) {
        return;
    }

    // mkdir warns on failure. The warning is suppressed so the thrown exception is the only signal.
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new HtmlSanitizerException(
            HtmlSanitizerError::Configuration,
            'HTML purifier definition cache path is not usable',
        );
    }
    if (!is_writable($path)) {
        throw new HtmlSanitizerException(
            HtmlSanitizerError::Configuration,
            'HTML purifier definition cache path is not usable',
        );
    }
}
```

Leave `purify()`, the constructor, and `schemeLookup()` as they are after Task 4.

- [ ] **Step 4: Run the sanitizer tests**

Run: `composer test -- --filter HtmlSanitizerTest`

Expected: PASS (19 tests). The four new tests plus the 15 from Task 4.

- [ ] **Step 5: Run the quality gate**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both exit 0. This is the same check `./bin/quality` runs inside Docker (`composer cs-check` + `composer phpstan`). Run `./bin/quality` instead once Docker is available again.

If cs-fixer reports diffs, run `vendor/bin/php-cs-fixer fix` then re-run both commands.

Typical PHPStan issues to fix immediately:

- `PurifyPolicy::$allowedSchemes` needs `@param list<string>` on the constructor. The property may also need `@var list<string>` if PHPStan does not keep the param type.
- `schemeLookup()` must keep `@return array<string, true>`.
- `HTMLPurifier::purify()` is annotated as returning `string`. Do not cast it unless PHPStan reports `mixed`.
- `@mkdir` is intentional. If PHPStan flags it, narrow the ignore to that line rather than removing the operator. A warning from `mkdir` would escape the `Configuration` exception.

- [ ] **Step 6: Manual checklist against the spec**

Confirm each item:

1. `purify(ReadableDocument): SafeDocument` exists. There is no `string` or `Utf8Html` overload.
2. `<script>`, `<iframe>`, `onclick`, `javascript:`, `ftp:`, `file:`, and `data:` images do not survive. `https` links, `mailto` links, and `https` images do.
3. An image is serialized with the XHTML empty-element form (`<img ... />`), which locks `HTML.Doctype` to `XHTML 1.0 Transitional`.
4. `sourceUrl` is unchanged. `title`, `excerpt`, and `siteName` are the same `PlainText` instances, including null excerpt and site name. A title of `Tom & Jerry` is not re-escaped.
5. Whitespace-only HTML and a script-only fragment return `SafeDocument`. They do not throw. Whitespace is not trimmed.
6. `new PurifyPolicy()` allows only `http`, `https`, and `mailto`. Empty lists, `javascript`, `data`, `file`, `ftp`, and non-lowercase tokens throw `\InvalidArgumentException`.
7. A policy of `['https']` keeps an `https` href and drops `mailto`.
8. A missing cache directory is created. A file path and an unwritable directory throw `HtmlSanitizerError::Configuration` with message `HTML purifier definition cache path is not usable`.
9. `debug: true` purifies the script fixture to the same `<p>x</p>` as debug off, and does so when the cache path is a file.
10. No `Orchestrator`, `PipelineOptions`, or `HTML.Allowed` code was added. `src/index.php` and `bin/run` are untouched. `PlainText` was not copied into this namespace.

- [ ] **Step 7: Commit**

```bash
git add src/HtmlSanitizer/HtmlSanitizer.php tests/HtmlSanitizer/HtmlSanitizerTest.php
git commit -m "$(cat <<'EOF'
feat(html-sanitizer): require a writable definition cache unless debugging

EOF
)"
```

If Step 5 required formatting or type fixes in other HtmlSanitizer files, include those files in this commit.

---

## Self-review (plan author)

**Spec coverage:**

| Spec area | Task |
|-----------|------|
| `HtmlSanitizerError` + default messages | Task 1 |
| `HtmlSanitizerException` wraps the vendor throwable as `$previous` | Task 1; catch in Task 4 |
| `PurifyPolicy` defaults and MVP allowlist | Task 2 |
| Reject empty list, `javascript` / `data` / `file`, and any other scheme | Task 2 |
| Cache path is not a policy field | Task 2 class shape; constructor in Task 4 |
| `SafeDocument` fields | Task 3 |
| `purify(ReadableDocument): SafeDocument` | Task 4 |
| Only `content` is purified; metadata instances copied | Task 4 |
| `sourceUrl` propagated | Task 4 |
| Script, `javascript:`, `https`, `mailto`, `ftp`, https image | Task 4 |
| `iframe` and event-handler attributes | Task 4 (`iframe`, `onclick`) |
| Other disallowed schemes in attributes (`file:`, `data:`) | Task 4 |
| Policy scheme list is what Purifier enforces | Task 4 `https`-only case |
| Empty and whitespace-only fragment is success | Task 4 |
| `Core.Encoding`, `HTML.Doctype`, `HTML.Trusted` | Task 4 `config()`; image serialization locks the doctype |
| `URI.AllowedSchemes` lookup map | Task 4 `schemeLookup()` |
| Create cache directory when missing | Task 5 |
| Unusable cache path → `Configuration` | Task 5 |
| `debug` sets `Cache.DefinitionImpl = null` and `Core.CollectErrors = true` | Task 5 |
| Debug does not change stripping | Task 5 |
| `HTMLPurifier_Exception` → `Configuration`; other throwables → `Unexpected` | Task 4 catch; no v4.19.1 fixture makes `purify()` throw |
| Out of scope (`HTML.Allowed`, orchestrator, `PlainText` escaping, raw string API) | Global Constraints; checklist in Task 5 |

**Placeholder scan:** no TBD / TODO implementation steps. Task 5 replaces `config()` by showing the whole method. Vendor output strings are the v4.19.1 results, not pseudocode.

**Type consistency:** `purify(ReadableDocument $document): SafeDocument` is stable from Task 4 through Task 5. `SafeDocument` argument order is `($html, $title, $excerpt, $siteName, $sourceUrl)` in Task 3 and Task 4. `PurifyPolicy` argument order is `($allowedSchemes = ['http', 'https', 'mailto'], $debug = false)` in Task 2 and every later call. `HtmlSanitizerException` argument order is `($error, $message = '', $previous = null)`. `HtmlSanitizer` constructor argument order is `($policy = null, $definitionCachePath = null)`.

**Deviations from the spec’s literal sketches (required for this codebase):**

- Policy rejection throws `\InvalidArgumentException`, the same style as `FetchPolicy` and `ExtractPolicy`. `assert()` is not used, because `zend.assertions` can disable it. `HtmlSanitizerException` stays reserved for `purify()` failures.
- `HtmlSanitizer` stores the policy with `$policy ?? new PurifyPolicy()` inside the constructor, matching `HttpFetcher`, instead of a `new` default on the parameter.
- `SafeDocument`’s constructor is public, matching the spec sketch and `ReadableDocument`. “Constructible only from HtmlSanitizer” is the production rule, not a private constructor.
- There is no logger. The spec constructor has none, so collector text is not written anywhere. `Core.CollectErrors = true` still runs in debug, and its messages are not part of `SafeDocument`.
- `Unexpected` is implemented and not fixture-tested. HTMLPurifier v4.19.1 does not throw for the dirty fragments in this plan.
- A missing cache directory does not throw inside Purifier. The adapter creates it, or throws `Configuration`, before `purify()`.
