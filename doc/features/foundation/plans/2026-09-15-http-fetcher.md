# HttpFetcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Phase 2 HttpFetcher so a pinned `SafeFetchTarget` becomes a bounded `FetchedPage` (body, Content-Type, status, request URI) or a tagged `HttpFetcherException`.

**Architecture:** A single Guzzle GET, pinned to `UrlGuard`'s already-checked IPs via `CURLOPT_RESOLVE`, with redirects and HTTP-error exceptions disabled at the client so status is checked explicitly. Content-Type and body size are enforced as local gates after the transfer. Production builds its own cURL-backed Guzzle client; unit tests inject a client backed by Guzzle's `MockHandler` so no real network I/O runs. Types live under `Yumo\LogRead\HttpFetcher\`.

**Tech Stack:** PHP `^8.5`, PHPUnit `^11`, Guzzle `^8.0` (already required in `composer.json`), PSR-3 (`psr/log`, already a transitive dependency), PSR-4 `Yumo\LogRead\` → `src/`.

**Spec:** `doc/features/foundation/specs/HttpFetcher.md` (also `doc/features/foundation/feature.md` Phase 2)

## Global Constraints

- PHP `^8.5`; `ext-curl` is a hard Composer requirement (DNS pinning depends on it).
- Namespace root: `Yumo\LogRead\` → `src/`; tests: `Yumo\LogRead\Tests\` → `tests/`.
- Documentation, identifiers, commit messages, and PHPDoc in **English**.
- Fail closed: every fetch problem throws `HttpFetcherException` tagged with `HttpFetcherError` — never return a partial or unbounded payload.
- **No redirects (MVP):** a `3xx` status throws `HttpFetcherError::Redirect`; `Location` is never fetched.
- **No charset decoding here:** `FetchedPage::$body` stays opaque bytes; `EncodingNormalizer` (a later phase) is the only stage that decodes to UTF-8.
- The Guzzle client sets `http_errors => false`; status is checked explicitly in `fetch()` — never rely on Guzzle throwing for `4xx`/`5xx`.
- Unit tests must not perform real network I/O: inject a Guzzle `Client` backed by `MockHandler`.
- **Environment note for this plan's execution:** Docker is unavailable in the current environment, so every command below runs PHP/Composer directly on the host (`vendor/bin/phpunit`, `composer test`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`) instead of the `./bin/*` Docker wrappers. When Docker is available again, `./bin/quality` must still pass on the final code — the commands are equivalent, only the execution wrapper differs.
- Do not implement `EncodingNormalizer`, `ArticleExtractor`, `HtmlSanitizer`, or `Orchestrator` in this plan.
- Leave `bin/run` as non-authoritative scratch; do not replace it until Orchestrator (Phase 6).

---

## File Structure

| Path | Responsibility |
|------|----------------|
| `src/HttpFetcher/FetchPolicy.php` | Immutable timeouts, body size cap, headers; self-validating construction |
| `src/HttpFetcher/HttpFetcherError.php` | Failure kind enum (`Transport`, `HttpStatus`, `Redirect`, `ContentType`, `BodyTooLarge`) + `defaultMessage()` |
| `src/HttpFetcher/HttpFetcherException.php` | Single exception carrying `HttpFetcherError` |
| `src/HttpFetcher/FetchedPage.php` | Immutable bounded response: body, Content-Type, status, request URI |
| `src/HttpFetcher/HttpFetcher.php` | Pinned Guzzle GET; Content-Type + size gates; error mapping; production client factory |
| `tests/HttpFetcher/FetchPolicyTest.php` | Construction defaults and rejection of invalid values |
| `tests/HttpFetcher/HttpFetcherExceptionTest.php` | Default vs custom message, `$previous` |
| `tests/HttpFetcher/FetchedPageTest.php` | Field storage, empty body allowed |
| `tests/HttpFetcher/HttpFetcherTest.php` | `fetch()` behavior: success, DNS pin, all five error kinds, pin-skip warning, debug logging |

---

### Task 1: `FetchPolicy`

**Files:**
- Create: `src/HttpFetcher/FetchPolicy.php`
- Test: `tests/HttpFetcher/FetchPolicyTest.php`

**Interfaces:**
- Consumes: nothing from later tasks
- Produces:
  - `final readonly class FetchPolicy { public function __construct(public int $timeoutSeconds = 10, public int $connectTimeoutSeconds = 5, public int $maxBytes = 5_000_000, public string $userAgent = 'LogRead/0.1', public string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', public bool $debug = false) }`
  - Invalid construction (non-positive `timeoutSeconds`/`connectTimeoutSeconds`/`maxBytes`, blank `userAgent`/`accept`) throws `\InvalidArgumentException` — this is a programmer/config error, not a pipeline failure, so it does **not** use `HttpFetcherError` (that enum has no case for it).

- [ ] **Step 1: Write the failing test**

Create `tests/HttpFetcher/FetchPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\FetchPolicy;

final class FetchPolicyTest extends TestCase
{
    public function test_defaults_construct_without_error(): void
    {
        $policy = new FetchPolicy();

        self::assertSame(10, $policy->timeoutSeconds);
        self::assertSame(5, $policy->connectTimeoutSeconds);
        self::assertSame(5_000_000, $policy->maxBytes);
        self::assertSame('LogRead/0.1', $policy->userAgent);
        self::assertSame('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', $policy->accept);
        self::assertFalse($policy->debug);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_invalid_values(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): FetchPolicy, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'zero timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(timeoutSeconds: 0),
                'timeoutSeconds must be positive',
            ],
            'negative timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(timeoutSeconds: -1),
                'timeoutSeconds must be positive',
            ],
            'zero connect timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(connectTimeoutSeconds: 0),
                'connectTimeoutSeconds must be positive',
            ],
            'negative connect timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(connectTimeoutSeconds: -1),
                'connectTimeoutSeconds must be positive',
            ],
            'zero maxBytes' => [
                static fn (): FetchPolicy => new FetchPolicy(maxBytes: 0),
                'maxBytes must be positive',
            ],
            'negative maxBytes' => [
                static fn (): FetchPolicy => new FetchPolicy(maxBytes: -1),
                'maxBytes must be positive',
            ],
            'empty userAgent' => [
                static fn (): FetchPolicy => new FetchPolicy(userAgent: ''),
                'userAgent must not be empty',
            ],
            'blank userAgent' => [
                static fn (): FetchPolicy => new FetchPolicy(userAgent: '   '),
                'userAgent must not be empty',
            ],
            'empty accept' => [
                static fn (): FetchPolicy => new FetchPolicy(accept: ''),
                'accept must not be empty',
            ],
        ];
    }

    public function test_debug_flag_is_settable(): void
    {
        $policy = new FetchPolicy(debug: true);
        self::assertTrue($policy->debug);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter FetchPolicyTest`

Expected: FAIL (`FetchPolicy` not found).

- [ ] **Step 3: Write minimal implementation**

Create `src/HttpFetcher/FetchPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Fixed timeouts, body size cap, and outgoing headers for one `HttpFetcher`.
 *
 * Construction rejects non-positive timeouts and size cap, and blank header values,
 * so an `HttpFetcher` never runs with a silently unusable policy.
 */
final readonly class FetchPolicy
{
    /**
     * @param int $timeoutSeconds Total request timeout, in seconds
     * @param int $connectTimeoutSeconds Connection timeout, in seconds
     * @param int $maxBytes Hard cap on the decoded response body size, in bytes
     * @param string $userAgent Outgoing `User-Agent` header value
     * @param string $accept Outgoing `Accept` header value
     * @param bool $debug When true, Guzzle transfer summaries are logged at PSR-3 `debug` level
     */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 5,
        public int $maxBytes = 5_000_000,
        public string $userAgent = 'LogRead/0.1',
        public string $accept = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        public bool $debug = false,
    ) {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be positive');
        }
        if ($connectTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be positive');
        }
        if ($maxBytes <= 0) {
            throw new \InvalidArgumentException('maxBytes must be positive');
        }
        if (trim($userAgent) === '') {
            throw new \InvalidArgumentException('userAgent must not be empty');
        }
        if (trim($accept) === '') {
            throw new \InvalidArgumentException('accept must not be empty');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter FetchPolicyTest`

Expected: PASS (11 tests: defaults, 9 invalid-value cases, debug flag).

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/FetchPolicy.php tests/HttpFetcher/FetchPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): add self-validating FetchPolicy

EOF
)"
```

---

### Task 2: Error model (`HttpFetcherError` + `HttpFetcherException`)

**Files:**
- Create: `src/HttpFetcher/HttpFetcherError.php`
- Create: `src/HttpFetcher/HttpFetcherException.php`
- Test: `tests/HttpFetcher/HttpFetcherExceptionTest.php`

**Interfaces:**
- Consumes: nothing from later tasks
- Produces:
  - `enum HttpFetcherError { case Transport; case HttpStatus; case Redirect; case ContentType; case BodyTooLarge; public function defaultMessage(): string }`
  - `final class HttpFetcherException extends \RuntimeException { public function __construct(public readonly HttpFetcherError $error, string $message = '', ?\Throwable $previous = null) }`

- [ ] **Step 1: Write the failing test**

Create `tests/HttpFetcher/HttpFetcherExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;

final class HttpFetcherExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(HttpFetcherError $error, string $expected): void
    {
        $e = new HttpFetcherException($error);
        self::assertSame($expected, $e->getMessage());
        self::assertSame($error, $e->error);
    }

    /**
     * @return array<string, array{HttpFetcherError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'transport' => [HttpFetcherError::Transport, 'HTTP transport failed (timeout, connect, or network error)'],
            'http status' => [HttpFetcherError::HttpStatus, 'HTTP response status is not successful'],
            'redirect' => [HttpFetcherError::Redirect, 'HTTP redirect is not followed'],
            'content type' => [HttpFetcherError::ContentType, 'Response Content-Type is not an allowed HTML type'],
            'body too large' => [HttpFetcherError::BodyTooLarge, 'Response body exceeds the configured size limit'],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $e = new HttpFetcherException(HttpFetcherError::Transport, 'connect refused', $previous);
        self::assertSame('connect refused', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter HttpFetcherExceptionTest`

Expected: FAIL (classes not found).

- [ ] **Step 3: Write minimal implementation**

Create `src/HttpFetcher/HttpFetcherError.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Kind of failure reported by `HttpFetcherException`.
 *
 * In every case the fetch must be treated as failed; the enum only tells callers
 * *why*, so logs or exit codes can differ without a hierarchy of exception classes.
 */
enum HttpFetcherError
{
    /** The transfer itself failed: timeout, connection refused, or another network error. */
    case Transport;

    /** The response status was not `2xx` and not a redirect (for example `404` or `500`). */
    case HttpStatus;

    /** The response status was `3xx`. Redirects are disabled; the `Location` is not fetched. */
    case Redirect;

    /** The `Content-Type` header was missing or was not an allowed HTML type. */
    case ContentType;

    /** The response body exceeded the configured size cap, by header or while streaming. */
    case BodyTooLarge;

    /**
     * Returns the standard message for this kind of failure when the throw site does not provide a more specific one.
     */
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
```

Create `src/HttpFetcher/HttpFetcherException.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Thrown when `HttpFetcher` cannot produce a `FetchedPage`.
 *
 * Inspect `$error` to tell transport, status, redirect, Content-Type, and body-size failures apart.
 */
final class HttpFetcherException extends \RuntimeException
{
    public function __construct(
        /** Why the fetch failed. */
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

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter HttpFetcherExceptionTest`

Expected: PASS (6 tests: 5 default-message cases + custom message).

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/HttpFetcherError.php src/HttpFetcher/HttpFetcherException.php tests/HttpFetcher/HttpFetcherExceptionTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): add HttpFetcherError and HttpFetcherException

EOF
)"
```

---

### Task 3: `FetchedPage`

**Files:**
- Create: `src/HttpFetcher/FetchedPage.php`
- Test: `tests/HttpFetcher/FetchedPageTest.php`

**Interfaces:**
- Consumes: nothing from later tasks
- Produces:
  - `final readonly class FetchedPage { public function __construct(public string $body, public string $contentType, public int $statusCode, public string $requestUri) }`

- [ ] **Step 1: Write the failing test**

Create `tests/HttpFetcher/FetchedPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\FetchedPage;

final class FetchedPageTest extends TestCase
{
    public function test_stores_all_fields(): void
    {
        $page = new FetchedPage(
            body: '<html></html>',
            contentType: 'text/html; charset=utf-8',
            statusCode: 200,
            requestUri: 'https://example.com/',
        );

        self::assertSame('<html></html>', $page->body);
        self::assertSame('text/html; charset=utf-8', $page->contentType);
        self::assertSame(200, $page->statusCode);
        self::assertSame('https://example.com/', $page->requestUri);
    }

    public function test_empty_body_is_allowed(): void
    {
        $page = new FetchedPage('', 'text/html', 204, 'https://example.com/');
        self::assertSame('', $page->body);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter FetchedPageTest`

Expected: FAIL (`FetchedPage` not found).

- [ ] **Step 3: Write minimal implementation**

Create `src/HttpFetcher/FetchedPage.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Bounded result of a successful `HttpFetcher::fetch()` call.
 *
 * `$body` is opaque bytes: it may not be UTF-8 and its declared charset (if any) lives
 * only in `$contentType`. Decoding into UTF-8 is `EncodingNormalizer`'s job, not this type's.
 */
final readonly class FetchedPage
{
    /**
     * @param string $body Raw decoded response bytes, no larger than the policy's `maxBytes`
     * @param string $contentType Full `Content-Type` header value, including any `charset` parameter
     * @param int $statusCode HTTP status code; always `2xx` on a successful fetch
     * @param string $requestUri URI that was requested, copied from `SafeFetchTarget::$requestUri`
     */
    public function __construct(
        public string $body,
        public string $contentType,
        public int $statusCode,
        public string $requestUri,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter FetchedPageTest`

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/FetchedPage.php tests/HttpFetcher/FetchedPageTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): add FetchedPage value object

EOF
)"
```

---

### Task 4: `HttpFetcher` core — success path, Transport, Redirect, HttpStatus, ContentType

**Files:**
- Create: `src/HttpFetcher/HttpFetcher.php`
- Create: `tests/HttpFetcher/HttpFetcherTest.php`

**Interfaces:**
- Consumes: `FetchPolicy`, `FetchedPage`, `HttpFetcherError`, `HttpFetcherException`, `Yumo\LogRead\UrlGuard\SafeFetchTarget` (already implemented; see `src/UrlGuard/SafeFetchTarget.php`)
- Produces:
  - `final class HttpFetcher { public function __construct(FetchPolicy $policy, ClientInterface $client, ?LoggerInterface $logger = null); public function fetch(SafeFetchTarget $target): FetchedPage }`
  - Task 4 keeps `$policy` and `$client` **required** so tests compile without a production client factory. Task 5 adds the body-size gates that consume `$policy->maxBytes`. Task 6 makes both optional (`?FetchPolicy $policy = null, ?ClientInterface $client = null`), matching the spec's final public constructor.

**Implementation note — why `request('GET', …)` and not `get(…)`:** the spec's pseudocode calls `$client->get($target->requestUri, $options)`. Guzzle's concrete `Client` class does offer a `get()` shorthand (via `ClientTrait`), but **`GuzzleHttp\ClientInterface` does not declare it** — only `send`, `sendAsync`, `request`, `requestAsync` are part of the interface. Since `HttpFetcher` depends on `ClientInterface` (so any Guzzle-compatible client can be injected in tests), typing `$client->get(...)` fails PHPStan level 8 with "Call to an undefined method `GuzzleHttp\ClientInterface::get()`." Use `$this->client->request('GET', $target->requestUri, $options)` instead — it is declared on the interface, behaves identically, and was verified against the installed Guzzle `8.2.0` with both `MockHandler` and PHPStan level 8.

Canonical gate order (must match tests below):
1. Transfer throws (`\Throwable` from `request()`) → `Transport`, with `$previous`.
2. Status `3xx` → `Redirect`.
3. Status not `2xx` (and not `3xx`, already handled) → `HttpStatus`.
4. `Content-Type` missing or not `text/html`/`application/xhtml+xml` (prefix match before `;`, case-insensitive) → `ContentType`.
5. (Task 5) Body size gates.
6. Success → `FetchedPage`.

- [ ] **Step 1: Write the failing tests**

Create `tests/HttpFetcher/HttpFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

final class HttpFetcherTest extends TestCase
{
    private function target(): SafeFetchTarget
    {
        return new SafeFetchTarget(
            requestUri: 'https://example.com/article',
            host: 'example.com',
            port: 443,
            ips: ['203.0.113.10'],
        );
    }

    /**
     * @param list<mixed> $queue
     * @param-out MockHandler $mockOut
     */
    private function clientWithResponses(array $queue, ?MockHandler &$mockOut = null): Client
    {
        $mock = new MockHandler($queue);
        $mockOut = $mock;
        $stack = HandlerStack::create($mock);

        return new Client([
            'handler' => $stack,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public function test_success_returns_fetched_page(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>ok</html>'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('<html>ok</html>', $page->body);
        self::assertSame('text/html; charset=utf-8', $page->contentType);
        self::assertSame(200, $page->statusCode);
        self::assertSame('https://example.com/article', $page->requestUri);
    }

    public function test_success_allows_xhtml_content_type(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'application/xhtml+xml'], '<html/>'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('application/xhtml+xml', $page->contentType);
    }

    public function test_success_allows_empty_body_on_204(): void
    {
        $client = $this->clientWithResponses([
            new Response(204, ['Content-Type' => 'text/html']),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('', $page->body);
        self::assertSame(204, $page->statusCode);
    }

    public function test_pins_dns_via_curl_resolve_option(): void
    {
        $mock = null;
        $client = $this->clientWithResponses(
            [new Response(200, ['Content-Type' => 'text/html'], 'ok')],
            $mock,
        );
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        $fetcher->fetch($this->target());

        self::assertSame(
            ['example.com:443:203.0.113.10'],
            $mock->getLastOptions()['curl'][CURLOPT_RESOLVE],
        );
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failures_map_to_transport_error(\Throwable $failure): void
    {
        $client = $this->clientWithResponses([$failure]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::Transport, $e->error);
            self::assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function transportFailures(): array
    {
        $request = new Request('GET', 'https://example.com/article');

        return [
            'connect exception' => [new ConnectException('Connection timed out', $request)],
            'request exception' => [new RequestException('Network error', $request)],
        ];
    }

    #[DataProvider('redirectStatuses')]
    public function test_redirect_status_fails_closed(int $status): void
    {
        $client = $this->clientWithResponses([
            new Response($status, ['Location' => 'https://example.com/other']),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::Redirect, $e->error);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function redirectStatuses(): array
    {
        return [
            'status 301' => [301],
            'status 302' => [302],
            'status 307' => [307],
        ];
    }

    #[DataProvider('httpErrorStatuses')]
    public function test_non_success_status_fails_closed(int $status): void
    {
        $client = $this->clientWithResponses([
            new Response($status, ['Content-Type' => 'text/html'], 'error page'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::HttpStatus, $e->error);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function httpErrorStatuses(): array
    {
        return [
            'status 404' => [404],
            'status 500' => [500],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('disallowedContentTypes')]
    public function test_disallowed_content_type_fails_closed(array $headers): void
    {
        $client = $this->clientWithResponses([
            new Response(200, $headers, 'binary or other'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::ContentType, $e->error);
        }
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function disallowedContentTypes(): array
    {
        return [
            'pdf' => [['Content-Type' => 'application/pdf']],
            'json' => [['Content-Type' => 'application/json']],
            'missing header' => [[]],
        ];
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter HttpFetcherTest`

Expected: FAIL (`HttpFetcher` not found).

- [ ] **Step 3: Write minimal `HttpFetcher` implementation**

Create `src/HttpFetcher/HttpFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

/**
 * Downloads one already-guarded URL with hard limits and returns a bounded byte payload, or throws.
 */
final class HttpFetcher
{
    private FetchPolicy $policy;
    private ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(
        FetchPolicy $policy,
        ClientInterface $client,
        ?LoggerInterface $logger = null,
    ) {
        $this->policy = $policy;
        $this->client = $client;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws HttpFetcherException
     */
    public function fetch(SafeFetchTarget $target): FetchedPage
    {
        $options = [
            'curl' => [
                CURLOPT_RESOLVE => $target->curlResolveEntries(),
            ],
            'stream' => true,
        ];

        try {
            $response = $this->client->request('GET', $target->requestUri, $options);
        } catch (\Throwable $e) {
            throw new HttpFetcherException(HttpFetcherError::Transport, '', $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300 && $statusCode < 400) {
            throw new HttpFetcherException(
                HttpFetcherError::Redirect,
                sprintf('HTTP redirect (status %d) is not followed', $statusCode),
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new HttpFetcherException(
                HttpFetcherError::HttpStatus,
                sprintf('HTTP response status %d is not successful', $statusCode),
            );
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!$this->isAllowedContentType($contentType)) {
            throw new HttpFetcherException(
                HttpFetcherError::ContentType,
                $contentType === ''
                    ? 'Response has no Content-Type header'
                    : sprintf('Response Content-Type "%s" is not an allowed HTML type', $contentType),
            );
        }

        $body = (string) $response->getBody();

        return new FetchedPage($body, $contentType, $statusCode, $target->requestUri);
    }

    private function isAllowedContentType(string $contentType): bool
    {
        if ($contentType === '') {
            return false;
        }

        $mimeType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mimeType === 'text/html' || $mimeType === 'application/xhtml+xml';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter HttpFetcherTest`

Expected: PASS (14 tests covering success, DNS pin option, transport, redirect, status, and Content-Type gates). Body is read with a plain `(string) $response->getBody()` cast for now; Task 5 replaces this with a size-capped streaming read.

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/HttpFetcher.php tests/HttpFetcher/HttpFetcherTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): implement HttpFetcher success path and status/redirect/content-type gates

EOF
)"
```

---

### Task 5: Body size gates (`Content-Length` precheck + streaming cap)

**Files:**
- Modify: `src/HttpFetcher/HttpFetcher.php`
- Modify: `tests/HttpFetcher/HttpFetcherTest.php`

**Interfaces:**
- Consumes: `$this->policy->maxBytes` (already on `FetchPolicy` from Task 1)
- Produces:
  - `private function assertDeclaredLengthWithinLimit(ResponseInterface $response): void` — throws `BodyTooLarge` when `Content-Length` is present, `Content-Encoding` is **absent**, and the declared length exceeds `maxBytes`. Skipped when `Content-Encoding` is present: a declared length there describes the *encoded* transfer size, not the decoded byte count this fetcher caps, so it is only advisory.
  - `private function readBodyWithinLimit(StreamInterface $stream): string` — reads in 8 KB chunks, throwing `BodyTooLarge` as soon as the accumulated size passes `maxBytes` (before allocating the whole oversize payload).

- [ ] **Step 1: Write the failing tests**

Append to `tests/HttpFetcher/HttpFetcherTest.php`, right before the final closing `}`:

```php
    public function test_content_length_over_max_fails_before_reading_body(): void
    {
        $client = $this->clientWithResponses([
            new Response(
                200,
                ['Content-Type' => 'text/html', 'Content-Length' => '1000'],
                'small body under the declared length',
            ),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 10), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::BodyTooLarge, $e->error);
        }
    }

    public function test_content_length_is_advisory_when_content_encoding_present(): void
    {
        $client = $this->clientWithResponses([
            new Response(
                200,
                [
                    'Content-Type' => 'text/html',
                    'Content-Length' => '1000',
                    'Content-Encoding' => 'gzip',
                ],
                'short',
            ),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 100), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame('short', $page->body);
    }

    public function test_body_exceeding_limit_while_streaming_fails_closed(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'this body is too long for the cap'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 5), $client, new NullLogger());

        try {
            $fetcher->fetch($this->target());
            self::fail('Expected HttpFetcherException');
        } catch (HttpFetcherException $e) {
            self::assertSame(HttpFetcherError::BodyTooLarge, $e->error);
        }
    }

    public function test_body_exactly_at_max_bytes_succeeds(): void
    {
        $body = str_repeat('a', 10);
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], $body),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(maxBytes: 10), $client, new NullLogger());

        $page = $fetcher->fetch($this->target());

        self::assertSame($body, $page->body);
        self::assertSame(10, strlen($page->body));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter HttpFetcherTest`

Expected: FAIL. The three `BodyTooLarge` cases fail because nothing enforces `maxBytes` yet (bodies are small enough that Task 4's plain read succeeds instead of throwing).

- [ ] **Step 3: Add the size gates**

In `src/HttpFetcher/HttpFetcher.php`, add these imports:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
```

Replace the body-read line in `fetch()`:

```php
        $body = (string) $response->getBody();

        return new FetchedPage($body, $contentType, $statusCode, $target->requestUri);
```

with:

```php
        $this->assertDeclaredLengthWithinLimit($response);

        $body = $this->readBodyWithinLimit($response->getBody());

        return new FetchedPage($body, $contentType, $statusCode, $target->requestUri);
```

Add these two private methods after `isAllowedContentType()`:

```php
    private function assertDeclaredLengthWithinLimit(ResponseInterface $response): void
    {
        $contentEncoding = $response->getHeaderLine('Content-Encoding');
        if ($contentEncoding !== '') {
            return;
        }

        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            return;
        }

        $declaredBytes = (int) $contentLength;
        if ($declaredBytes > $this->policy->maxBytes) {
            throw new HttpFetcherException(
                HttpFetcherError::BodyTooLarge,
                sprintf('Content-Length %d exceeds the %d byte limit', $declaredBytes, $this->policy->maxBytes),
            );
        }
    }

    private function readBodyWithinLimit(StreamInterface $stream): string
    {
        $chunkSize = 8192;
        $body = '';
        $bytesRead = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read($chunkSize);
            $bytesRead += strlen($chunk);
            if ($bytesRead > $this->policy->maxBytes) {
                throw new HttpFetcherException(
                    HttpFetcherError::BodyTooLarge,
                    sprintf('Response body exceeds the %d byte limit while streaming', $this->policy->maxBytes),
                );
            }
            $body .= $chunk;
        }

        return $body;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter HttpFetcherTest`

Expected: PASS (18 tests: the 14 from Task 4 plus the 4 body-size cases above).

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/HttpFetcher.php tests/HttpFetcher/HttpFetcherTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): enforce maxBytes via Content-Length precheck and streaming cap

EOF
)"
```

---

### Task 6: Production client factory, optional constructor, DNS-pin-skip warning, debug logging

**Files:**
- Modify: `src/HttpFetcher/HttpFetcher.php`
- Modify: `tests/HttpFetcher/HttpFetcherTest.php`

**Interfaces:**
- Consumes: `GuzzleHttp\Client`, `GuzzleHttp\HandlerStack`, `GuzzleHttp\Middleware`, `GuzzleHttp\MessageFormatter`
- Produces:
  - `HttpFetcher::__construct(?FetchPolicy $policy = null, ?ClientInterface $client = null, ?LoggerInterface $logger = null)` — final public constructor shape from the spec.
  - `private function createDefaultClient(FetchPolicy $policy): ClientInterface` — cURL-capable default handler; policy timeouts and headers; `allow_redirects => false`; `http_errors => false`; when `$policy->debug`, pushes `Middleware::log(...)` named `'debug_log'`.
  - Pin-skip warning: whenever `$client` was **injected** (not built by `createDefaultClient()`), every `fetch()` call logs a `warning` with `requestUri` and `host` in the context, before making the request.

**Design note — how "handler cannot honour `CURLOPT_RESOLVE`" is decided:** the spec says HttpFetcher should warn when the active handler cannot apply `CURLOPT_RESOLVE`. Guzzle 8's `HandlerStack` composes handlers through nested closures (and its own default handler selection wraps a stream-handler fallback around the cURL handler), so there is no version-stable way to ask an arbitrary injected `ClientInterface` "can you pin?". This plan uses a simple, testable proxy instead: **pin support is assumed only for the client `HttpFetcher` builds itself** (`createDefaultClient()`, which always uses the default cURL-capable handler — `ext-curl` is a hard Composer requirement). Any externally injected client (which in practice means test doubles backed by `MockHandler`) logs the pin-skipped warning on every request. This matches every row of the spec's testing-guidance table: MockHandler-backed tests expect the warning; the production cURL path does not emit it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/HttpFetcher/HttpFetcherTest.php`, right before the final closing `}`, and add `use Psr\Log\AbstractLogger;` to the `use` block at the top of the file:

```php
    public function test_injected_client_logs_pin_skipped_warning(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client, $logger);

        $fetcher->fetch($this->target());

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        ));
        self::assertNotEmpty($warnings);
        self::assertSame('example.com', $warnings[0]['context']['host']);
        self::assertSame('https://example.com/article', $warnings[0]['context']['requestUri']);
    }

    public function test_no_client_given_marks_client_as_internal(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy());

        $reflection = new \ReflectionProperty(HttpFetcher::class, 'clientIsInternal');
        self::assertTrue($reflection->getValue($fetcher));
    }

    public function test_injected_client_is_not_marked_internal(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ]);
        $fetcher = new HttpFetcher(new FetchPolicy(), $client);

        $reflection = new \ReflectionProperty(HttpFetcher::class, 'clientIsInternal');
        self::assertFalse($reflection->getValue($fetcher));
    }

    public function test_constructs_with_no_arguments(): void
    {
        $fetcher = new HttpFetcher();
        self::assertInstanceOf(HttpFetcher::class, $fetcher);
    }

    public function test_default_client_attaches_debug_log_middleware_when_debug_enabled(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy(debug: true));

        self::assertTrue($this->defaultClientHasMiddleware($fetcher, 'debug_log'));
    }

    public function test_default_client_skips_debug_log_middleware_when_debug_disabled(): void
    {
        $fetcher = new HttpFetcher(new FetchPolicy(debug: false));

        self::assertFalse($this->defaultClientHasMiddleware($fetcher, 'debug_log'));
    }

    private function defaultClientHasMiddleware(HttpFetcher $fetcher, string $name): bool
    {
        $clientProperty = new \ReflectionProperty(HttpFetcher::class, 'client');
        /** @var Client $client */
        $client = $clientProperty->getValue($fetcher);

        /** @var HandlerStack<callable> $stack */
        $stack = $client->getConfig('handler');
        $stackProperty = new \ReflectionProperty(HandlerStack::class, 'stack');
        /** @var list<array{0: callable, 1: string}> $entries */
        $entries = $stackProperty->getValue($stack);

        foreach ($entries as [, $middlewareName]) {
            if ($middlewareName === $name) {
                return true;
            }
        }

        return false;
    }
```

These tests use `\ReflectionProperty` to inspect the private `client`/`clientIsInternal` fields and Guzzle's private `HandlerStack::$stack` list. This is a deliberate, narrow use of reflection to cover the internal client factory "lightly" (per the spec's testing guidance) without weakening `HttpFetcher`'s `final class` / private-field encapsulation with test-only seams.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter HttpFetcherTest`

Expected: FAIL. `new HttpFetcher()` and `new HttpFetcher(new FetchPolicy(), $client)` (2 args) do not compile against Task 5's required `(FetchPolicy $policy, ClientInterface $client, ...)` constructor; `clientIsInternal` does not exist yet; no warning is logged.

- [ ] **Step 3: Add the factory, optional constructor, and pin-skip warning**

Replace the top of `src/HttpFetcher/HttpFetcher.php` (imports through the constructor) with:

```php
<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

/**
 * Downloads one already-guarded URL with hard limits and returns a bounded byte payload, or throws.
 *
 * Performs a single pinned HTTP GET: the TCP connection is forced to the public IP addresses
 * that `UrlGuard` already checked (DNS pinning, via cURL's `CURLOPT_RESOLVE`), redirects are
 * refused rather than followed, and both the declared and the streamed body size are capped.
 * This class does not decode character encoding; `FetchedPage::$body` is opaque bytes.
 *
 * `$page = (new HttpFetcher())->fetch($guardResult->safe);`
 *
 * Unit tests inject a Guzzle client backed by `MockHandler` so no real HTTP happens:
 * `new HttpFetcher($policy, $clientWithMockHandler, $logger)`.
 */
final class HttpFetcher
{
    /** Timeouts, body size cap, and outgoing headers for this fetcher. */
    private FetchPolicy $policy;

    /** Guzzle-compatible client used to perform the GET request. */
    private ClientInterface $client;

    /** Destination for the DNS-pin-skipped warning and, when `FetchPolicy::$debug` is true, transfer summaries. */
    private LoggerInterface $logger;

    /**
     * Whether `$client` was built by `createDefaultClient()` rather than injected.
     *
     * The internal client always uses Guzzle's default cURL-capable handler (`ext-curl` is a
     * hard Composer requirement), so DNS pinning is assumed to work there. Guzzle composes an
     * injected client's handler stack from closures that do not expose the terminal transport,
     * so an externally injected client's ability to honour `CURLOPT_RESOLVE` cannot be checked
     * reliably; every request made through one logs a pin-skipped warning instead of guessing.
     */
    private bool $clientIsInternal;

    /**
     * Creates a fetcher. When `$policy`, `$client`, or `$logger` are omitted, production defaults
     * are used: a `FetchPolicy` with default limits, an internal cURL-backed Guzzle client built
     * from that policy, and a no-op logger.
     */
    public function __construct(
        ?FetchPolicy $policy = null,
        ?ClientInterface $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->policy = $policy ?? new FetchPolicy();
        $this->logger = $logger ?? new NullLogger();
        $this->clientIsInternal = $client === null;
        $this->client = $client ?? $this->createDefaultClient($this->policy);
    }
```

In `fetch()`, add the pin-skip check as the very first statement in the method body:

```php
    public function fetch(SafeFetchTarget $target): FetchedPage
    {
        if (!$this->clientIsInternal) {
            $this->logger->warning(
                'DNS pin skipped: injected HTTP client is not the internal cURL client and cannot be assumed to honour CURLOPT_RESOLVE',
                ['requestUri' => $target->requestUri, 'host' => $target->host],
            );
        }

        $options = [
```

Add the factory method after `readBodyWithinLimit()`:

```php
    /**
     * Builds the production Guzzle client: default cURL-capable handler, fixed timeouts and
     * headers from `$policy`, redirects disabled, and HTTP-status exceptions disabled (status
     * is checked explicitly in `fetch()` instead of relying on Guzzle to throw for it).
     */
    private function createDefaultClient(FetchPolicy $policy): ClientInterface
    {
        $stack = HandlerStack::create();
        if ($policy->debug) {
            $stack->push(
                Middleware::log(
                    $this->logger,
                    new MessageFormatter(MessageFormatter::SHORT),
                    'debug',
                ),
                'debug_log',
            );
        }

        return new Client([
            'handler' => $stack,
            'timeout' => $policy->timeoutSeconds,
            'connect_timeout' => $policy->connectTimeoutSeconds,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $policy->userAgent,
                'Accept' => $policy->accept,
            ],
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter HttpFetcherTest`

Expected: PASS (24 tests: the 18 from Task 5 plus pin-skip warning, internal-flag checks, no-args construction, and debug-middleware presence/absence).

- [ ] **Step 5: Commit**

```bash
git add src/HttpFetcher/HttpFetcher.php tests/HttpFetcher/HttpFetcherTest.php
git commit -m "$(cat <<'EOF'
feat(http-fetcher): add production client factory, optional constructor, and pin-skip warning

EOF
)"
```

---

### Task 7: Exit criteria verification

**Files:**
- Modify: none (verification only)
- Test: full suite

**Interfaces:**
- Consumes: all Task 1–6 deliverables
- Produces: confirmation that Phase 2 exit criteria hold

- [ ] **Step 1: Run the full test suite**

Run: `composer test`

Expected: PASS, 100+ tests total (52 pre-existing UrlGuard tests + 43 new: 11 `FetchPolicy` + 6 `HttpFetcherException` + 2 `FetchedPage` + 24 `HttpFetcher`).

- [ ] **Step 2: Run the quality gates directly (Docker unavailable in this environment)**

Run:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both exit `0` with no diffs / no errors. This is the same check `./bin/quality` runs inside Docker (`composer cs-check` + `composer phpstan`) — run it via `./bin/quality` instead once Docker is available again; do not skip this gate.

- [ ] **Step 3: Manual checklist against the spec**

Confirm each item:

1. `fetch(SafeFetchTarget): FetchedPage` exists and throws `HttpFetcherException`.
2. Redirects (`3xx`) are never followed; `Location` is not read as a URL.
3. `http_errors` is `false` on the production client; status is checked explicitly (never relies on Guzzle throwing for `4xx`/`5xx`).
4. `Content-Type` gate allows only `text/html` and `application/xhtml+xml` (prefix match, case-insensitive), rejecting missing/other types.
5. Body size is capped both via `Content-Length` precheck (skipped when `Content-Encoding` is present) and via a streaming read cap.
6. `FetchedPage` is immutable and carries `body`, `contentType`, `statusCode`, `requestUri`.
7. Production default is `new HttpFetcher()` → internal cURL-backed client, `FetchPolicy` defaults, `NullLogger`.
8. Pin-skip `warning` is logged for injected (test) clients, not for the internal production client.
9. `FetchPolicy::$debug === true` attaches Guzzle's log middleware at `debug` level; `false` does not.
10. No `EncodingNormalizer` / `ArticleExtractor` / `HtmlSanitizer` / `Orchestrator` code was added.
11. `bin/run` left untouched.

- [ ] **Step 4: Commit only if Step 3 found fixes**

If verification required code changes, commit them:

```bash
git add -A
git commit -m "$(cat <<'EOF'
test(http-fetcher): close gaps found in Phase 2 exit criteria

EOF
)"
```

If nothing changed, skip the commit.

---

## Self-review (plan author)

**Spec coverage:**

| Spec area | Task |
|-----------|------|
| `FetchPolicy` (self-validating VO) | Task 1 |
| `HttpFetcherError` / `HttpFetcherException` | Task 2 |
| `FetchedPage` | Task 3 |
| Pinned GET; Transport/Redirect/HttpStatus/ContentType gates | Task 4 |
| Body size gates (`Content-Length` precheck + streaming cap) | Task 5 |
| `createDefaultClient` (cURL handler, timeouts, headers, `allow_redirects=false`, `http_errors=false`, debug log middleware); optional constructor; pin-skip warning | Task 6 |
| Exit criteria + quality gates | Task 7 |
| Out of scope (EncodingNormalizer, redirects-follow, Orchestrator, …) | Explicitly excluded in Global Constraints |

**Placeholder scan:** no TBD/TODO implementation steps; every code block was written, then actually executed against PHP `8.5.10` / Guzzle `8.2.0` / PHPUnit `11.5.56` / PHPStan level 8 / php-cs-fixer during plan authoring (43 new tests passing, 0 PHPStan errors, 0 cs-fixer diffs on the final Task 6 state, and each intermediate task state re-verified with PHPUnit).

**Type consistency:** `fetch(SafeFetchTarget): FetchedPage`; error cases `Transport` / `HttpStatus` / `Redirect` / `ContentType` / `BodyTooLarge` match the spec; final constructor `(?FetchPolicy, ?ClientInterface, ?LoggerInterface)` matches the spec's public API section.

**Deviations from the spec's literal pseudocode (both required for correctness, verified against installed versions):**
- `$client->request('GET', $uri, $options)` instead of `$client->get($uri, $options)` — `get()` is not declared on `GuzzleHttp\ClientInterface` (see Task 4 implementation note).
- Pin-skip-warning detection uses "was the client injected" rather than deep handler-stack introspection — see Task 6 design note.
