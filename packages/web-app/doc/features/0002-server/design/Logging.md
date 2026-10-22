# Logging

Module design for [logging.md](../specs/logging.md). This slice adds no glossary term. It adopts [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md) and [adr/0006](../../../adr/0006-kernel-tests-live-in-integration.md).

Revised by [ArchiveLog.md](ArchiveLog.md): `RequestIdProcessor` and the `domain` lines of `ArchiveService` live there. This file keeps the HTTP lines.

The functional spec is [logging.md](../specs/logging.md). This design records the module. The replacements are:

- Two `application` lines, `http.request.start` before the action and `http.request.finish` after it. Both stay level `info`.
- The id rule is `RequestIdFactory`. The facts stored on the request are `EnrichedRequest`, built by `RequestEnricher`.
- `LogApiRequestTest` reads that value on the request. It does not read log files, and it does not assert `X-Request-Id` on the response.

## Modules

`Yumo\Eleanor\Request\RequestIdFactory` is the id rule. `fromHeader(?string $value): string` keeps `$value` when it is 1 to 128 characters and every character is an ASCII letter (`A-Z`, `a-z`), an ASCII digit, `.`, `_`, or `-`. Any other value, including `null` and `''`, returns a new UUID version 4. The UUID is built with `random_bytes`. No other package is added. `symfony/uid` is not installed.

`Yumo\Eleanor\Request\UuidRequestIdFactory` is the implementation. The subscriber depends on the interface. A later implementation can replace this one. This slice adds no configuration key.

`Yumo\Eleanor\Request\RequestEnricher` is a final class. It depends on `RequestIdFactory`. `enrich(Request $request): EnrichedRequest` reads the request and returns the facts. It takes the first `X-Request-Id` value only. A second value cannot replace a rejected first one. It passes that first value, or `null` when the header is absent, to the factory. `userAgent` and `referer` are the header values when the client sent a non-empty one. A missing header or an empty value becomes `null`.

`Yumo\Eleanor\Request\EnrichedRequest` holds `requestId`, `userAgent`, and `referer`. The last two are `null` when the enricher omitted them.

`Yumo\Eleanor\EventSubscriber\LogApiRequest` is the subscriber. Symfony registers it through the existing autoconfigure. It depends on `RequestEnricher` and on the `application` logger (`monolog.logger.application`). The controller does not read User-Agent, Referer, or `X-Request-Id`, and does not set the response header.

On `kernel.controller`, for a main request, the subscriber enriches the request, stores the `EnrichedRequest` on the request attributes under `EnrichedRequest::class`, stores the start from `hrtime(true)`, and then writes `http.request.start`. The `/api` import loads only the API controllers, so the subscriber does not filter by class name.

On `kernel.response`, when that attribute is present, the subscriber sets `X-Request-Id` to `requestId` and then writes `http.request.finish`. The duration is the elapsed nanoseconds from the stored start until this write, divided by one million and truncated to an integer.

A 404 and a 405 are raised from the router during `kernel.request`. `kernel.controller` does not run, the attribute is absent, and `kernel.response` does not write and does not set the header.

## Lines

Both lines are channel `application`, level `info`. The handler is not `fingers_crossed`, so the start line reaches the file before `create()` runs.

`http.request.start` context: `method`, `route` (the route name), `path`, `request_id`. `path` is the path and the query string, with no scheme and no host, and with no trailing `?` when the query is absent. `user_agent` and `referer` are included only when the enriched value is non-null.

`http.request.finish` context: `request_id`, `status` (integer), `duration_ms` (non-negative integer).

The lines do not include the body, a bearer token, article HTML, or upstream response headers. They do not carry an exception. `POST /api/archives` still returns 200, `{"ok":true}`, and `application/json`.

## Monolog

Eleanor installs `symfony/monolog-bundle` on the Symfony 8.1 line. The channel list keeps `deprecation` and adds `application` and `domain`.

Files live in `%kernel.logs_dir%`. Symfony's file is `%kernel.environment%.log`. Eleanor's file is `eleanor.%kernel.environment%.log`. `dev` and `test` append with a stream. `prod` uses `rotating_file`, `max_files: 7`, and the bundle's `{filename}-{date}` name, so the files are `prod-YYYY-MM-DD.log` and `eleanor.prod-YYYY-MM-DD.log`.

The `eleanor` handler is top-level in every environment. It accepts only `application` and `domain`. Its level is `debug` in `dev` and `test`, and `info` in `prod`. Its formatter is `monolog.formatter.json` with `include_stacktraces: true`.

Symfony's handlers keep the recipe's levels, `fingers_crossed` placement, 404 and 405 exclusion, production buffer of 50, `event` exclusion in `dev`, and the console handler in `dev` and `prod`. `test` has no console handler. The production nested handler is a rotating file with the JSON formatter, not stderr. Deprecations stay on stderr, channel `deprecation`, with that JSON formatter and `include_stacktraces: true`. `dev` and `test` line formatters do not set the flag.

Every Symfony handler that would otherwise receive all channels excludes `application` and `domain`. On `fingers_crossed` the exclusion sits on `main`, not on `nested`. The console handler excludes them too. The `app` channel stays on the Symfony side. A trace is the formatter's `file:line` list, and only on a record whose context already holds an exception.

## Errors

An invalid id is not an HTTP error. The factory returns a UUID version 4.

A failed log write is caught at the subscriber and not rethrown. The start write failing still lets `create()` run. The finish path sets `X-Request-Id` before the write, so a failed finish write keeps the status, the body, and the header. The enriched attribute is stored before either write.

When the action throws after the start line, the kernel still produces a response. The subscriber writes `http.request.finish` at `info` with that response's status. Symfony's uncaught exception stays on the `app` channel at the framework's level. A 500 there stays `critical`. This slice adds no Eleanor exception type.

## Test

`tests/Request/UuidRequestIdFactoryTest.php` does not boot the kernel. It covers a kept value at length 1 and 128, `null`, `''`, a space, a newline, a character outside the list, and length 129. Each rejection is a UUID version 4.

`tests/Integration/LogApiRequestTest.php` is a `WebTestCase` in `Yumo\Eleanor\Tests\Integration`. It boots the kernel in `test` and sends the request. After the kernel returns, it reads the `EnrichedRequest` stored on the request the client sent. It does not open a log file, and it does not assert the response header.

A POST that sends a valid id, a User-Agent, and a Referer finds those three values on the attribute. A missing id is a UUID version 4. A rejected first id stays rejected when a second value is valid. A missing or empty User-Agent, and a missing or empty Referer, are `null`. HEAD on `/api/archives` and a POST on an unmatched path leave the attribute absent.

`CreateArchiveRouteTest` and `KernelTest` stay. They do not assert the log or the attribute.

The test sees the attribute after the call returns. It does not pause the action. A failed disk write, a stack trace, rotation, the `dev` line formatter, stderr deprecations, and the text of the two lines stay decisions. The article-reader suite is not a seam. A browser check is not a seam.

## Out of scope

The request-id processor and the `domain` writer. Both are [ArchiveLog.md](ArchiveLog.md). Masking an owner email. The firewall, the bearer token, and the owner. Reading a URL pointer, refusing a payload, fetching a page, purifying an article, and storing an archive. Changing the empty-route body, status, or content type. A fourth environment. Filtering Symfony's matched-route line, or changing the level of Symfony's exception record. An automated proof of the log files, of rotation, of the `dev` line formatter, of stderr deprecations, or of a stack trace. PHP-FPM, the reverse proxy, and the distributable image. A new glossary term.
