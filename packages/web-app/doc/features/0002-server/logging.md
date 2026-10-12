**Status:** ready-for-agent

The module design is [specs/Logging.md](specs/Logging.md). Where the two files disagree, the spec wins. It replaces the single `http.request` line with `http.request.start` and `http.request.finish`, adds `RequestEnricher` and `EnrichedRequest`, and points `LogApiRequestTest` at the enriched request instead of the log files.

## Problem Statement

Eleanor answers `POST /api/archives`, and a person running it cannot see that call in a log they keep on the machine. Symfony's own lines are not that record: a successful call in production leaves no request line, and nothing echoes an id the client can match to the log. Dev, test, and production need a place to read Eleanor's own lines without mixing them into Symfony's.

## Solution

Install Monolog and write two local logs. One keeps Symfony's channels. The other keeps Eleanor's `application` and `domain` channels in one file, still distinct by channel name. Eleanor's file is JSON in every environment. A finished `/api` action writes one `application` line, `http.request`, and the response repeats `X-Request-Id`. The empty-route body stays `{"ok":true}`. Nothing writes `domain` yet.

## User Stories

1. As a person running Eleanor, I want Monolog to write the logs, so that the application does not invent a second logger.
2. As a person running Eleanor, I want only the `dev`, `test`, and `prod` environments, so that `staging` is not a fourth mode.
3. As a person running Eleanor, I want Symfony's channels in one log, so that framework lines stay together.
4. As a person running Eleanor, I want Eleanor's lines in a second log, so that I can read the application without the framework noise.
5. As a person running Eleanor, I want `application` and `domain` to stay different channel names inside that second log, so that I can filter the pipeline later without a third file.
6. As a person running Eleanor, I want the default `app` channel to stay with Symfony, so that an untagged logger does not land in Eleanor's log.
7. As a person running Eleanor, I want a record to appear in one log only, so that Eleanor's line is not copied into Symfony's log.
8. As a person running Eleanor, I want Eleanor's log to be JSON in `dev`, `test`, and `prod`, so that each line is one object.
9. As a person running Eleanor, I want Symfony's `dev` and `test` logs to keep the recipe's line formatter, so that those files stay readable as text.
10. As a person running Eleanor, I want Symfony's `prod` log to keep the recipe's JSON formatter, so that production framework lines stay one object per line.
11. As a person running Eleanor, I want `dev` to append to one file per log, so that a development log is not rotated.
12. As a person running Eleanor, I want `test` to append to one file per log, so that a test run is not rotated.
13. As a person running Eleanor, I want `prod` to rotate both logs, one file a day, so that a long run does not grow a single file.
14. As a person running Eleanor, I want production to keep seven rotated files, so that the eighth day drops the oldest.
15. As a person running Eleanor, I want Eleanor's log written on every call, so that a successful archive is not held back until an error.
16. As a person running Eleanor, I want Eleanor's threshold to be `debug` in `dev` and `test`, so that a debug line is visible there.
17. As a person running Eleanor, I want Eleanor's threshold to be `info` in `prod`, so that debug noise stays out of production.
18. As a person running Eleanor, I want Symfony's `dev` log to keep the recipe's `debug` threshold and to leave out the `event` channel, so that the framework log does not change its filter.
19. As a person running Eleanor, I want the console handler in `dev` and `prod`, so that a console command still prints Symfony's lines.
20. As a person running Eleanor, I want the console handler to leave out `application` and `domain`, so that Eleanor's lines stay in the file.
21. As a person running Eleanor, I want Symfony's `test` and `prod` logs to keep `fingers_crossed` at `error`, so that a quiet success still writes nothing on the framework side.
22. As a person running Eleanor, I want 404 and 405 to keep failing to open that buffer, so that a missing path does not dump the framework buffer.
23. As a person running Eleanor, I want production's framework buffer to stay at 50 records, so that the recipe's cap is unchanged.
24. As a person running Eleanor, I want deprecations to stay on stderr, so that the deprecation channel does not move into the rotated file.
25. As a person running Eleanor, I want a stack trace in Eleanor's JSON only when that record already carries an exception, so that a normal request line stays short.
26. As a person running Eleanor, I want that stack to be file and line, so that call arguments stay out of the JSON.
27. As a person running Eleanor, I want the same stack flag on Symfony's production JSON, so that an uncaught exception's record can carry the trace.
28. As a person running Eleanor, I want `dev` and `test` line logs to stay without a stack, so that the line formatter does not grow a trace string.
29. As a person running Eleanor, I want a deprecation that carries no exception to gain no trace from that flag, so that the shared production JSON formatter does not invent one.
30. As a client of Eleanor, I want `POST /api/archives` to write one `application` line, so that the empty route leaves a record.
31. As a client of Eleanor, I want that line's message to be `http.request`, so that I can find the round trip by a fixed text.
32. As a client of Eleanor, I want that line's level to be `info`, so that a 200 is kept in production and a later 500 does not retitle the same line.
33. As a client of Eleanor, I want the method on that line, so that I can see the verb.
34. As a client of Eleanor, I want the route name `create_archive` on that line, so that the name stays stable if the prefix changes.
35. As a client of Eleanor, I want the path and the query string together on that line, so that the query I sent is visible.
36. As a client of Eleanor, I want that path to omit the scheme and the host, so that the line is not a full URL.
37. As a client of Eleanor, I want a path with no query to omit the question mark, so that an empty query is not a trailing mark.
38. As a client of Eleanor, I want the HTTP status on that line, so that I can see the outcome.
39. As a client of Eleanor, I want the duration in whole milliseconds, so that I can see how long the call took.
40. As a client of Eleanor, I want that duration measured from the moment the request is first seen until the line is written, so that the number covers the action.
41. As a client of Eleanor, I want a request id on that line, so that I can tie the call to the response.
42. As a client of Eleanor, I want to send `X-Request-Id` and see that same value in the log, so that my id is the one that was stored.
43. As a client of Eleanor, I want a missing `X-Request-Id` to be replaced with a new id, so that the line still has one.
44. As a client of Eleanor, I want an id of letters, digits, `.`, `_`, and `-`, from 1 to 128 characters, to be kept, so that a normal token survives.
45. As a client of Eleanor, I want any other id, including a space, a newline, an empty value, or a value longer than 128 characters, to be replaced, so that the log does not store it.
46. As a client of Eleanor, I want only the first `X-Request-Id` value to be considered, so that a second value cannot override a rejected first one.
47. As a client of Eleanor, I want the generated id to be a UUID version 4, so that the replacement has one shape.
48. As a client of Eleanor, I want the response to repeat that same id in `X-Request-Id`, so that I can match the body I received to the line.
49. As a client of Eleanor, I want the stub body to stay `{"ok":true}`, so that the log slice does not change the empty route's payload.
50. As a client of Eleanor, I want the response content type to stay `application/json`, so that the log slice does not change the empty route's media type.
51. As a client of Eleanor, I want my User-Agent copied onto the line when I send it, so that the caller is visible.
52. As a client of Eleanor, I want the User-Agent key absent when I send none, so that an empty agent is not stored as a blank.
53. As a client of Eleanor, I want my Referer copied onto the line when I send it, so that the referring URI is visible.
54. As a client of Eleanor, I want the Referer key absent when I send none, so that an empty referrer is not stored as a blank.
55. As a client of Eleanor, I want the request body left out of the line, so that a URL pointer is not copied into the log.
56. As a client of Eleanor, I want a bearer token left out of the line, so that a credential is not stored.
57. As a client of Eleanor, I want article HTML and upstream response headers left out of the line, so that the page copy stays out of the log.
58. As a client of Eleanor, I want the line written only after an `/api` action has run, so that the status and the duration exist.
59. As a client of Eleanor, I want a 405 on `/api/archives` to add no Eleanor line and no `X-Request-Id`, so that a refused verb stays on Symfony's channels.
60. As a client of Eleanor, I want a path that matches no route to add no Eleanor line and no `X-Request-Id`, so that a 404 stays on Symfony's channels.
61. As a client of Eleanor, I want the controller to ignore User-Agent, Referer, and `X-Request-Id`, so that those headers stay out of the action.
62. As a client of Eleanor, I want the response id set even when the log file cannot be written, so that a full disk does not turn the stub into a 500.
63. As a client of Eleanor, I want the stub status and body kept when the log file cannot be written, so that logging is not part of the command's outcome.
64. As a developer of Eleanor, I want the `domain` channel routed to Eleanor's log and left silent, so that the pipeline logger arrives with the orchestrator.
65. As a developer of Eleanor, I want Symfony's matched-route line left as the framework writes it, so that this slice does not edit the router log.
66. As a developer of Eleanor, I want an uncaught exception to stay on Symfony's `app` channel at the framework's level, so that a 500 remains `critical` there and is not rewritten as `error`.
67. As a developer of Eleanor, I want one HTTP test to prove the line and the response id, so that a regression fails in the existing application suite.
68. As a developer of Eleanor, I want that test to run in `test` and to boot the kernel, so that it does not call a listener as a plain function.
69. As a developer of Eleanor, I want the empty-route test and the kernel boot test kept, so that those seams still pass.
70. As a developer of Eleanor, I want no new glossary term, so that a channel name stays a logger name.

## Implementation Decisions

- Eleanor installs `symfony/monolog-bundle` on the line that matches the installed Symfony 8.1. No other package is added. The kernel's allowed environments stay `prod`, `dev`, and `test`.
- Two logs share the kernel logs directory. Symfony's log is named with the environment (`dev`, `test`, `prod`). Eleanor's log is `eleanor.` plus that same environment name. `dev` and `test` append to those files. `prod` rotates each of them, one file a day, and keeps seven files.
- Symfony's handlers keep the recipe's levels, `fingers_crossed` placement, 404 and 405 exclusion, production buffer of 50, `event` exclusion in `dev`, and console handler in `dev` and `prod`. The production Symfony handler writes the rotating file instead of stderr, with the recipe's JSON formatter. Deprecations stay on stderr, on the `deprecation` channel, with that same JSON formatter.
- Every Symfony handler that would otherwise receive all channels excludes `application` and `domain`. The console handler excludes them too. The `app` channel remains on the Symfony side.
- Eleanor's handler accepts only `application` and `domain`. It is not `fingers_crossed` in any environment. Its level is `debug` in `dev` and `test`, and `info` in `prod`. Its formatter is JSON in every environment. `includeStacktraces` is on for that formatter, and on for the production JSON formatter Symfony already uses. `dev` and `test` line formatters do not set it. A trace is the formatter's `file:line` list, and only on a record whose context already holds an exception. The `http.request` record does not carry one.
- One listener in front of `/api` actions reads the request before the action and writes the line after the action has produced a response. The controller does not read User-Agent, Referer, or `X-Request-Id`, and does not set the response header. The `domain` channel has no writer in this slice.
- The line is one JSON record on `application`, level `info`, message `http.request`. The context holds `method`, `route` (the route name), `path` (the path and the query string, with no scheme and no host; no trailing `?` when the query is absent), `status` (the HTTP status integer), `duration_ms` (whole milliseconds from the listener's first sight of the request until the write), and `request_id`. `user_agent` and `referer` are present only when the client sent those headers.
- `request_id` is the first `X-Request-Id` value when that value is 1 to 128 characters and each character is a letter, a digit, `.`, `_`, or `-`. Otherwise Eleanor generates a UUID version 4. The response of the action sets `X-Request-Id` to that same value. A failed log write does not change the action's status or body, and the header is still set.
- The record does not include the body, a bearer token, article HTML, or upstream response headers. Symfony's matched-route record and Symfony's exception record stay as the framework writes them, including a `critical` level for an uncaught 500 on the `app` channel.
- `POST /api/archives` still returns 200, `{"ok":true}`, and `application/json`. This slice adds the response header. A 405 and a 404 do not write the Eleanor line and do not receive `X-Request-Id`.
- This contract follows adr/0005 and adr/0006. It adds no glossary term. Channel names are not glossary terms.

## Testing Decisions

- A good test observes one HTTP seam. It sends a request through the kernel and reads the response header and the Eleanor test log. It does not call the listener or the action as a function, boot `prod` to inspect handlers, open a socket, or start the temporary server.
- The seam is the existing integration `WebTestCase` suite, in the test environment, per adr/0006. The empty-route test and the kernel boot test stay.
- On `POST /api/archives` the test asserts the stub status, content type, and body, plus `X-Request-Id`. The Eleanor test log gains one JSON line on `application`, message `http.request`, level `info`, with `method`, `route` `create_archive`, `path` including the query string and excluding the scheme and host, integer `status`, non-negative integer `duration_ms`, and `request_id` equal to the response header.
- The same seam covers a client id that is kept, a missing id that is generated as a UUID version 4, a rejected id that is replaced, User-Agent and Referer present and absent, a 405, and a 404. The 405 and the 404 add no Eleanor line and no `X-Request-Id`. The Symfony test log does not contain `http.request`.
- The seam does not assert a failed disk write, a stack trace, rotation, the `dev` line formatter, or stderr deprecations. Those stay decisions in this contract.
- The library suite is not the seam. A browser check is not a seam.

## Out of Scope

- A writer on the `domain` channel, and any pipeline hop.
- Masking an owner email.
- The firewall, the bearer token check, and the owner.
- Reading a URL pointer, refusing a payload, fetching a page, purifying an article, and storing an archive.
- Changing the empty-route body, status, or content type.
- A fourth environment, including `staging`.
- Filtering Symfony's matched-route line, or changing the level of Symfony's exception record.
- An automated proof of rotation, of the `dev` line formatter, of stderr deprecations, or of a stack trace.
- PHP-FPM, the reverse proxy, and the distributable image.
- A new glossary term.

## Further Notes

- `research/Logging.md` is not this contract. Where it disagrees, this file wins: production application logs rotate to a file rather than stderr, deprecations stay on stderr, `http.request` is always `info`, `path` includes the query string, and the response repeats `X-Request-Id`.
- The empty-route contract left logging out of scope. This slice is that logging. The stub body remains the stub.
- `domain` is reserved for the ArticleReader slice. This slice only routes the channel.
