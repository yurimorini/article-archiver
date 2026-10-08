# Request facts and the log line

This file records a research session. It is not a final decision. A later session can replace it. A spec adopts it when implementation starts.

The question is one rule, shown twice. A fact that arrives on the HTTP request stays on that request until something reads it in order to decide. The thing that decides gives the fact its name. The archive command is not that thing for User-Agent, Referer, a request id, a client address, or a credential.

Symfony's channels keep the recipe's `fingers_crossed` handler. `application` and `domain` are written on every call, together, as JSON. The config that is on writes them to stderr. A second config, off, rotates one file and keeps seven.

## The rule

[Types.md](Types.md) already splits the body from the headers. The JSON body is a DTO and that DTO stays in the controller. The archive service receives the URL string. There is no URL value object in Eleanor. `UrlGuard`, inside the orchestrator, judges the string.

The same split applies to a header. The header is wire data. It becomes a named input only for the code that branches on it.

| Who reads the fact | What they hold | Example |
| --- | --- | --- |
| Nothing in the application decides with it | The log context, an array beside the message | User-Agent, Referer, request id |
| A framework mechanism decides, and Eleanor has no rule of its own | The framework's type, at the edge | The limiter's string key. `UserInterface` in the firewall |
| Eleanor owns the rule the use case runs | An object of Eleanor's | The URL string, and the owner |

## When the type is Eleanor's

This is an aspiration, applied when it pays, not a layer in front of every framework call.

Symfony types name Symfony's mechanisms. `UserInterface` belongs with the firewall: the authenticator loads it, the session holds it, the access check reads it. The rate limiter's factory, its storage, and `consume()` belong with that limiter. A listener that only asks Symfony to refuse the call keeps those types. Eleanor does not wrap them.

Eleanor adds a type when Eleanor owns a rule that a use case runs, and the business domain must see that rule without speaking the framework. The test is one imagined swap, for that rule, not a general adapter. The counter can stay Symfony's. What would move is the rule: who is counted, the limit, and the refusal, stated in Eleanor's words. While the rule is only "this framework limiter rejects the request," there is no Eleanor object. When the rule is Eleanor's — this person may archive this many pages — the use case receives Eleanor's subject and applies that rule. The counter behind it can still be the framework's.

The person follows the same test. A business rule about that person does not take `UserInterface`. The edge builds Eleanor's object from the framework user, and that object is the argument. Until a use case has the rule, the mapping is not written. The configured token never becomes the object: the firewall compares it and drops it.

[PSR-3 §1.3](https://www.php-fig.org/psr/psr-3/) says the context is an array for information that does not fit in the message string. It is not a type the archive service accepts. [Monolog](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md) ("Adding extra data in the records") has two ways to fill a record: the context array on that call, and a processor that adds `extra` to every record. A processor runs before the handlers and does not add a parameter to the code that logged. That is how a request id reaches the orchestrator's hop lines without the archive service receiving the header.

[Symfony's processor page](https://symfony.com/doc/current/logging/processors.html) registers that callable on the logger. `TokenProcessor` copies the current user's name, roles, and whether they are authenticated. `RouteProcessor` copies the route. That page says `WebProcessor` overrides data from Symfony's request. Monolog's own [`WebProcessor`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Processor/WebProcessor.php) is narrower: by default it copies `url`, `ip`, `http_method`, `server`, and `referrer` from `$_SERVER`. `user_agent` is in its field map and stays off unless the caller asks for it. Its `ip` is `REMOTE_ADDR`. Eleanor's listener still copies User-Agent and the request id itself. The archive service still logs through PSR-3 with whatever it already passes. The processor attaches the rest.

## What one HTTP call records

[OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), "Event attributes", says each entry records when, where, who, and what. For a web call that includes the entry-point URL and HTTP method, the source address, the user identity when known, the action, the result, the HTTP status, and the User-Agent. Note A on that page calls the shared id an interaction identifier: one id ties the events of a single call together, instead of forcing a later reader to rebuild the link.

[Investigations.md](Investigations.md) session 7 already names Eleanor's HTTP round: method, route, outcome, duration, and a correlation id shared with the pipeline log. [Types.md](Types.md) adds User-Agent, Referer, and a request id. If the client sends a request id, Eleanor keeps it. Otherwise Eleanor generates one.

[RFC 9110 §10.1.5](https://www.rfc-editor.org/rfc/rfc9110.html#section-10.1.5) defines `User-Agent` as information about the user agent that originated the request, used to scope interoperability problems and for analytics. [§10.1.3](https://www.rfc-editor.org/rfc/rfc9110.html#section-10.1.3) defines `Referer` as the URI reference of the resource from which the target URI was obtained, and says the field can reveal browsing history. Both are headers. Neither is a field of the archive command.

The fetched text of RFC 9110 does not define a request-id header. [§7.5](https://www.rfc-editor.org/rfc/rfc9110.html#section-7.5), "Response Correlation," pairs a response with its request on a connection. Eleanor's id is the interaction identifier from the cheat sheet and from [Types.md](Types.md).

Left out, already stated in [Open.md](Open.md) and matching the cheat sheet's "Data to exclude":

- The configured token, and any access token. The cheat sheet lists access tokens and authentication passwords among values that are removed, masked, or hashed rather than stored raw. Session identifiers, if logged at all, are replaced with a hash.
- The article HTML, and the upstream response headers.
- Stack traces and filesystem paths stay in the log and stay out of the JSON error document ([Flow.md](Flow.md), [Types.md](Types.md)). The cheat sheet allows extended details such as a stack trace, and it also says file paths may need special treatment. Eleanor already keeps them off the client.

The archived URL on a success line is a reading trace. The pipeline already puts it on the `domain` hops. Eleanor does not send the line off the machine ([Investigations.md](Investigations.md)).

The owner's email is written on `domain` only after the owner has been resolved. It is masked. Let `n` be the length of the whole address. The number of characters kept on each side is `min(3, (n - 1) // 2)`. When that number is 0, the value is `***`. Otherwise it is the first characters, then `***`, then the last characters. `yuri@example.com` is `yur***com`. `a@b.co` is `a@***co`. `ab` is `***`. The fixed `***` hides how long the middle was. Before the owner is resolved, the field is absent. The `application` line does not carry it.

## Where in the flow

[HttpKernel](https://symfony.com/doc/current/components/http_kernel.html), "The `kernel.request` Event", runs before the controller. A listener may add information to the `Request`, including its attributes bag, or it may return a response and stop the call. The firewall is that kind of listener. [Types.md](Types.md) puts the log listener in the same place: in front of every `/api` action, before the action, reading User-Agent, Referer, and the request id into the log context. The controller does not read those headers.

The outcome and the duration exist only after the action. The request id was captured earlier, so hop lines written during the action already carry it. HttpKernel describes [`kernel.response`](https://symfony.com/doc/current/components/http_kernel.html) as the moment to modify the response before it is sent, and [`kernel.terminate`](https://symfony.com/doc/current/components/http_kernel.html) as work after `handle()` returns. On PHP-FPM and FrankenPHP the response can already be on the wire when terminate runs; on other server APIs the client waits. Neither page assigns Eleanor's duration line to one of those events. The line is written once the outcome exists. It does not need the archive service to accept those headers.

Two moments, one context:

1. Before the action: copy User-Agent, Referer, and the request id into the log context.
2. After the outcome exists: one line with method, route, status, duration, and that same id.

## Three streams

A debug session and a test need to read one of three streams and leave the others out. The streams are Symfony's, Eleanor's HTTP application, and the pipeline.

PSR-3 has no channel. The channel is Monolog's. [Monolog](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md) ("Leveraging channels") gives each logger a name, prints that name on the record, and uses it to filter. [`LogRecord`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/LogRecord.php) stores it as `channel`, beside `message`, `context`, and `extra`. [Symfony](https://symfony.com/doc/current/logging/channels_handlers.html) already routes on that field. A handler's `channels` list keeps `security` or drops it with `!security`. The same page says that list works on a top-level handler. A handler nested under `fingers_crossed`, a buffer, or a group ignores it and sees every record it is given.

Symfony's own channels stay Symfony's: `request`, `security`, `event`, and the others the framework opens. `request` is the kernel's channel. Eleanor does not write the HTTP round there, or the two streams would be the same filter. The default channel `app` is whatever service was not given a channel. Eleanor does not use it for the two streams below, so an untagged service cannot hide inside them.

Eleanor names two channels and does not add a logger type:

| Stream | Channel | Who writes it |
| --- | --- | --- |
| Symfony | The framework's own names | The kernel, the firewall, the rest of Symfony |
| HTTP application | `application` | The round line, the controller, and the application service |
| Pipeline | `domain` | The logger Eleanor passes into the orchestrator |

The library takes a `LoggerInterface` and does not know the name. `OrchestratorFactory` gives that one logger to the orchestrator and to the HTTP fetcher, and to article extraction when that stage's debug switch is on. Eleanor binds the logger to `domain` before it passes it. The controller and the application service receive the logger bound to `application`. A swap of the HTTP framework would keep the two names and which code writes which. The handler that filters on `channel` would be the new framework's.

The request id is what joins the streams after a filter. It is copied onto each record by the processor, so a test that keeps only `domain` still sees which call those hops belonged to.

A test reads the captured records and keeps one channel. Separate files are the same filter, used when a person tails a log. They are not required for the test.

A client address or an API key used to refuse excess calls is an input of the limiter, not of the archive.

[Symfony Rate Limiter](https://symfony.com/doc/current/rate_limiter.html), "Using the Rate Limiter Service", builds a limiter from a string: "a unique identifier of the client (e.g. the client's IP address, a username/email, an API key)". The call is `RateLimiterFactoryInterface::create($key)`, then `consume()`. The example key is `$request->getClientIp()`. Another example uses `$request->headers->get('apikey')`. In the [8.1 factory](https://github.com/symfony/rate-limiter/blob/8.1/RateLimiterFactory.php), `create(?string $key = null)` concatenates that string into the limiter id. `consume()` takes a token count. The factory does not take the `Request`, and it does not take an archive.

The same page says that, in a real application, the check belongs in one listener on `kernel.request`, once for all requests, rather than in every controller method. `#[RateLimit]` (Symfony 8.1) sits on the controller and, when `key` is omitted, buckets by client IP, HTTP method, and path. A custom key is an expression or a closure over the request. That attribute is still HTTP-edge configuration. It is not an argument of the application service.

So the address gets a name, and the name is the limiter key: the subject being counted. It stays a string at the edge. The archive service does not receive it. That string is enough while Eleanor has no limit rule of its own. The framework's limiter is the mechanism, and it may stay the framework's.

[Martin Fowler, "Value Object"](https://martinfowler.com/bliki/ValueObject.html) (14 November 2016) separates two classes. Objects equal by the value of their properties are value objects. Objects told apart by identity are reference objects; he treats an entity in a domain model as a common form of reference object. A client address used only to count calls has value equality and no archive identity. The factory already takes the string, so this session does not add a wrapper around it. An Eleanor object appears later only for a limit rule Eleanor states itself. That object is the rule the use case would keep if the counter were replaced. It is not a second limiter.

[Investigations.md](Investigations.md) session 6 is a different limit: request size, fetches in flight, and a disk check, refused before the orchestrator runs. A per-client rate limit is not that session. If one is added, it sits with the firewall and the log listener. The Symfony page also says these limiters run inside the PHP process and do not replace a limit in the web server. Eleanor is not open to the public internet ([feature.md](../feature.md)), which is the setting where an in-process limiter is the local policy rather than an edge against the open network.

Behind a proxy, [Symfony's proxy page](https://symfony.com/doc/current/deployment/proxies.html) says `REMOTE_ADDR` is the proxy. The client address is in `Forwarded` or `X-Forwarded-For` only after `trusted_proxies` and `trusted_headers` name that proxy. Without that, a client can choose the address the limiter and the log would trust. The listener computes the string from the address it has decided to trust. That decision stays in the listener.

## The same rule: the caller

A header key that resolves to an email is the second example of the same split, not a second question.

[Symfony Security](https://symfony.com/doc/current/security.html), "The User", says a secured application has a user object, a class that implements `UserInterface`. The user provider loads that object from storage by a user identifier, "e.g. the user's email address". In the documented user class, `getUserIdentifier()` returns the email. The email is the identifier. That object is the framework's user: the firewall needs it. It is not the object a business rule receives. On Fowler's page the person, once Eleanor names one, is a reference object: equality is identity, the way a sales order is one order and not the text of its fields. An email string passed to `create()` as a limiter key is still the key, and it is still not that person.

[How to Write a Custom Authenticator](https://symfony.com/doc/current/security/custom_authenticator.html) puts the read of the header in `authenticate(Request)`. The method extracts the credential, turns it into a user identifier, and returns a passport whose `UserBadge` carries that identifier. The provider, or a loader closure on the badge, returns the `UserInterface`. On success, `onAuthenticationSuccess` returns `null` and the request continues with the user authenticated. The header is the credential's location. The token is the credential. Neither is the user object.

[Login.md](Login.md) already assigns that job to the firewall. It reads the credential the client sent and leaves an authenticated caller, or a refusal. Controllers do not choose the method. The first credential is the configured token, compared with configuration. [Login.md](Login.md) says people do not receive that token as their login. When human login exists, the stored identity is the allowlisted email, with the passkey's credential id and public key kept against it. The firewall then checks the session Eleanor issued.

[Storage.md](Storage.md) names the owner as the service argument. The edge builds that owner from the framework user. The header and the bearer token still stop at the firewall, the same way User-Agent stops in the log context. The log may still record who, because OWASP's "who" includes the user identity when it is known, and `TokenProcessor` already copies the identifier from the security token. Recording the identifier does not pass an object into `fetchArticle`.

The service argument is the owner. The firewall still holds `UserInterface`. The edge builds the owner from it. The service does not receive the header, the bearer token, the email as a substitute, or the framework user. The email remains the identifier on the allowlist, and the value the framework user returns from `getUserIdentifier()`.

The configured token is not logged. Neither object is a field of the archive command today.

## What does not get a type

- The log context is the PSR-3 array, plus Monolog `extra` from processors.
- User-Agent, Referer, and the request id are entries in that context.
- The bearer token is a credential. The firewall compares or resolves it, then drops it. It is not a property of the user object, and it is not in the log.
- The email identifies the person. It is not Eleanor's object for that person, and it is not `UserInterface`.
- `UserInterface` stays in the firewall. It crosses into a use case only by being mapped to Eleanor's object, and only once that use case has a rule about the person.
- The client address, when a framework limiter counts calls, is the string passed to `create()`. Eleanor names an object for a limit when the limit is Eleanor's rule, not when the counter is Symfony's.

No new glossary entry follows from this session. The person an archive belongs to is already the owner. The mask is not a term.

## The handler

The logger is Monolog, installed with `symfony/monolog-bundle`. The `logger` service is an alias of `monolog.logger`, and that logger is a `Monolog\Logger` whose default channel is `app` ([monolog-bundle 4.x config](https://github.com/symfony/monolog-bundle/blob/4.x/config/monolog.php)). Symfony's minimal stderr logger, the one present before the bundle, is not this logger.

The bundle recipe [3.7 `monolog.yaml`](https://github.com/symfony/recipes/blob/main/symfony/monolog-bundle/3.7/config/packages/monolog.yaml) is what a Flex app starts from. It has no `rotating_file`. [`RotatingFileHandler`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Handler/RotatingFileHandler.php) is a separate handler: one file per day, and `$maxFiles` defaults to `0`, which keeps every old file. The class comment calls that rotation a workaround and prefers logrotate when the host provides it. A simple PHP host does not. Eleanor uses this handler when the destination is a file.

| Environment | What the recipe writes | Formatter |
| --- | --- | --- |
| `dev` | A stream to `%kernel.logs_dir%/%kernel.environment%.log`, level `debug`, every channel except `event`. A console handler beside it | None set, so Monolog's line formatter |
| `test` | `fingers_crossed` at `error`, excluding HTTP 404 and 405, then a stream to the test log at `debug` | None set, so the line formatter |
| `prod` | `fingers_crossed` at `error`, excluding 404 and 405, `buffer_size: 50`, then a stream to `php://stderr`. Deprecations go to stderr on their own channel | `monolog.formatter.json` |

`fingers_crossed` holds the request's records and forwards them only when one record reaches the action level. `buffer_size: 50` is how many records stay in memory. In `dev` the recipe writes Symfony's channels. In `test` and `prod` it holds them until an error. That split stays, and it stays on Symfony's channels only. The `channels` list has to sit on the handler itself: a handler nested under `fingers_crossed` ignores the list and sees every record it is given.

`application` and `domain` share one top-level handler. It is not `fingers_crossed`, in any environment. A successful archive still writes its `info` lines. The formatter is [`JsonFormatter`](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Formatter/JsonFormatter.php) (`monolog.formatter.json`): one JSON object per line, stack traces left out unless `includeStacktraces` is set. The recipe turns that formatter on only for `prod` stderr. Eleanor uses it on these two channels in every environment.

Two configs are prepared. One is on:

| Config | On | Handler |
| --- | --- | --- |
| Stderr | Yes, for now | `stream` to `php://stderr`. Nothing to rotate |
| File | No | `rotating_file`, one file a day, `max_files: 7` |

Both write the same two channels into that one destination. The channel field is how a test, or a person, keeps one stream. A test reads the captured records. The test recipe's `fingers_crossed` would hide Symfony's `info` lines, not these two channels.

WordPress, on the simplest host, does less than this and does not rotate. [`wp_debug_mode()`](https://github.com/WordPress/WordPress/blob/master/wp-includes/load.php) runs only when `WP_DEBUG` is true. `WP_DEBUG` and `WP_DEBUG_LOG` default to false. When `WP_DEBUG_LOG` is true it sets PHP's `error_log` to `wp-content/debug.log`; a string value is used as the path instead. The [debugging handbook](https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/) describes that file and says the constants are for local testing and staging, not for a live site. It describes no rotation and no size cap. PHP then appends to that one file for as long as debug stays on. Eleanor keeps the part that fits a simple host: the application writes the file itself, with no log daemon. It does not keep WordPress's single unbounded file, and it does not turn the log off outside a debug constant.
