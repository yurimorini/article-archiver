# Payload and mapper

Module design for [payload-and-mapper.md](../payload-and-mapper.md). The decisions in that contract are closed. This slice adds no glossary term. It adopts [adr/0007](../../../adr/0007-archive-body-mapped-by-map-request-payload.md). [adr/0001](../../../adr/0001-archive-outcome-status.md) still owns archive outcomes. 415 is the media type, before that command. [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md) stays as the empty-route slice adopted it.

This slice replaces two rules in [EmptyRoute.md](EmptyRoute.md): `create` has no parameter, and a wrong content type is not 415. Logging stays the next implementation slice. This spec is slice 4.

## Module

`Yumo\Eleanor\Api\Controller\CreateArchiveController` stays the HTTP adapter. The action stays `create`. The class does not extend `AbstractController` and has no constructor. The route attribute stays path `/archives`, name `create_archive`, method POST, and format `json`. It has no alias.

The class moves from `src/Controller/Api/` (`Yumo\Eleanor\Controller\Api`) to `src/Api/Controller/`. One import loads that directory and the namespace `Yumo\Eleanor\Api\Controller`. It does not load `src/Api/`. The import prefix `/api`, `trailing_slash_on_root` false, and `stateless` true do not change. `routes.yaml` changes only the path and the namespace of that import. `services.yaml` does not change.

`Yumo\Eleanor\Api\Dto\CreateArchiveBody` is the body type. It is a final class in `src/Api/Dto/`. It is not a controller, not a glossary term, and not an archive, an article, or a URL pointer. It has one constructor parameter, promoted and public: `?string $url = null`. The constraint is `NotNull`. It has no `NotBlank`, no other property, and no other method.

`#[Exclude]` marks that class. The existing `Yumo\Eleanor\` resource would register it. The DTO does not enter a service, and the container does not construct it. This slice adds no service definition.

The action signature is:

```php
create(
    #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
    CreateArchiveBody $body,
): JsonResponse
```

The resolver maps the body before `create` runs. `create` does not read `$body->url`. It returns `JsonResponse::fromJsonString('{"ok":true}')`. It calls no archive service, fetches no page, and does not touch the session.

Unknown JSON properties stay ignored by the serializer default. This slice adds no serializer config and no validator config by hand. A Flex recipe that `symfony/serializer` or `symfony/validator` installs may stay. The property-info Flex recipe `config/packages/property_info.yaml` may stay too. `with_constructor_extractor` already defaults to true. `symfony/property-access` is also a direct package at `8.1.*`, because FrameworkBundle removes the object normalizer without it and `symfony/serializer` does not require it. `symfony/property-info` and `symfony/type-info` stay lock-only. No other direct package is added.

`symfony/serializer`, `symfony/validator`, and `symfony/property-access` are added at `8.1.*`.

## Response

An accepted POST returns 200 and `{"ok":true}` with content type `application/json`.

That 200 covers a string `url`, including `""`, `" "`, and a string that is not a usable URL. It covers an extra JSON property, `Content-Type: application/json; charset=utf-8`, a query string, and `Accept: text/html`. The matched route stays `create_archive`.

That 200 is the empty-route stub. It is not the stored-archive 200, and it is not the 201 of a new archive.

## Errors

The resolver refuses the body before `create` returns the stub. This slice adds no Eleanor exception type and does not build the problem document. The refusal body is Symfony's JSON error. Its words, its `cause` absence, and any debug header are not the contract.

400 with content type `application/json` covers malformed JSON, an empty body with `Content-Type: application/json`, a missing `url`, a null `url`, a non-string `url`, and a JSON value that is not that object: array, string, number, `null`, or boolean. `validationFailedStatusCode: 400` keeps a shape failure on 400.

415 with content type `application/json` covers a missing `Content-Type`, `text/plain`, `application/x-www-form-urlencoded`, and an empty body with no `Content-Type`. 415 stays distinct from 400.

GET and HEAD on `/api/archives` stay 405. `POST /api/archives/` and `/archives` without the prefix stay off this route. The skeleton's dev error route stays. This slice adds no owner email, bearer token, database path, archive directory, or log line.

## Test

One HTTP test is the automated seam: `CreateArchiveRouteTest`, a `WebTestCase` in `Yumo\Eleanor\Tests\Integration`, the flat file under `tests/Integration/`. The client sends the request through the kernel in the test environment. It reads the status, the content type, and, for an accepted call, the body and the matched route name. It does not call `create` as a function, assert the exception class, or assert the text of Symfony's error JSON.

Accepted cases each expect route `create_archive`, 200, `application/json`, and `{"ok":true}`:

- a string `url`, on the request that already carries the query string
- an empty `url`
- an extra JSON property

Refusal cases each expect `application/json` and do not expect a body string:

- 400 for malformed JSON, a missing `url`, a non-string `url`, and a JSON value that is not the object
- 415 for a missing `Content-Type` and for `text/plain`

The empty-route cases that treated a non-JSON body, a missing content type, or a non-string `url` as 200 take these statuses. GET, HEAD, the trailing slash, and the unprefixed path stay as they are. The kernel boot test stays.

A `url` of spaces, a string that is not a usable URL, a charset parameter, `Accept: text/html`, `application/x-www-form-urlencoded`, and an empty body sent as `application/json` share those paths and are not separate assertions.

`packages/web-app/README.md` documents this call. The curl sends `Content-Type: application/json` and `{"url":"https://example.com/a"}`. The documented body stays `{"ok":true}`. The server command does not change. The curl is a manual check. It is not a second automated seam.

The package quality command exiting 0 is an acceptance check. It is not a second test.

## Out of scope

Request logging. An archive service, `UrlGuard`, fetching a page, purifying an article, and storing an archive. The success body `url` and `title`, the 201 for a new archive, and the 200 for an archive the owner already has. Eleanor's problem document, `cause`, and `application/problem+json`. The firewall, the bearer token, and the owner email. PHP-FPM, the reverse proxy, and the distributable image. Request size limits, an idempotency key, HTML pages, CORS, HTTP caching, CSRF, and a version segment. A new glossary term.

The finished empty-route plan stays the record of the code written then. Moving the controller into `src/Api/Controller/` belongs to this slice.
