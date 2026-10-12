# Payload and Mapper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/archives` accepts a JSON object whose `url` is a string and still returns the empty-route stub, and refuses a bad body with 400 or a non-JSON media type with 415 before that stub.

**Architecture:** `CreateArchiveController` moves to `src/Api/Controller/` and stays the HTTP adapter. `#[MapRequestPayload]` maps `CreateArchiveBody` before `create` runs. The action ignores `url` and returns the same stub. One stateless `/api` import loads the controller directory and does not load the DTO directory.

**Tech Stack:** PHP 8.5, Symfony 8.1, `symfony/serializer` `8.1.*`, `symfony/validator` `8.1.*`, existing PHPUnit 11, `symfony/browser-kit` `8.1.*`, `./bin/composer`, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/PayloadAndMapper.md`

## Global Constraints

- The controller is `Yumo\Eleanor\Api\Controller\CreateArchiveController` in `src/Api/Controller/`. The action stays `create`. The class does not extend `AbstractController` and has no constructor.
- The route attribute stays path `/archives`, name `create_archive`, method POST, and format `json`. It has no alias.
- `routes.yaml` changes only the path and the namespace of the `api_controllers` import: `../src/Api/Controller/` and `Yumo\Eleanor\Api\Controller`. Prefix `/api`, `trailing_slash_on_root` false, and `stateless` true stay. The import does not load `src/Api/`.
- Do not edit `packages/web-app/config/services.yaml`. The DTO does not enter a service. `#[Exclude]` marks `CreateArchiveBody`. This slice adds no service definition.
- Do not hand-write serializer config or validator config. A Flex recipe that `symfony/serializer` or `symfony/validator` installs may stay. Do not edit `packages/web-app/config/routes/framework.yaml`.
- Direct packages added are `symfony/serializer`, `symfony/validator`, and `symfony/property-access`, all `8.1.*`. Property access was added because FrameworkBundle removes the object normalizer without it, and `symfony/serializer` does not require it. `symfony/property-info` and `symfony/type-info` stay lock-only. No other name is added to `require` or `require-dev`. A package that appears only in `composer.lock` may stay.
- The body type is `Yumo\Eleanor\Api\Dto\CreateArchiveBody` in `src/Api/Dto/`. One promoted public constructor parameter: `?string $url = null`, with `NotNull` and without `NotBlank`. No other property and no other method.
- The action parameter is `CreateArchiveBody $body`, mapped with `MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)`. `create` does not read `$body->url`. It returns `JsonResponse::fromJsonString('{"ok":true}')`. It calls no archive service, fetches no page, and does not touch the session.
- Unknown JSON properties stay ignored. Add no serializer config to reject them.
- An accepted POST returns 200 and `{"ok":true}` with content type `application/json`. That 200 is the empty-route stub.
- The refusal body is Symfony's JSON error. Do not assert its words, a `cause` field, or a debug header. Do not add an Eleanor exception type or a problem document.
- GET and HEAD on `/api/archives` stay 405. `POST /api/archives/` and `/archives` without the prefix stay off this route. The kernel boot test stays. The dev error route stays.
- One HTTP test, `CreateArchiveRouteTest`, is the automated seam. It does not call `create` as a function. No resolver unit test.
- Do not add a log line, an owner email, a bearer token, a database path, or an archive directory. No glossary term.
- `./bin/quality web-app` exiting 0 is an acceptance check, not a second test.

## Review Focus

1. `Content-Type: application/json; charset=utf-8` with a string `url` is 200, not 415. Task 1 accepted row `string url` sends that header and the query string.
2. `Accept: text/html` on an accepted body is still `application/json` and `{"ok":true}`. Task 1 accepted row `empty url` sends `HTTP_ACCEPT` `text/html`. The client sends no `Accept` unless the test sets it.
3. `{}` is 400, not 422. Task 1 refusal row `missing url` pins `validationFailedStatusCode: 400`. A required `string $url` is 500 on this row.
4. A JSON object with no `Content-Type` is 415, not 400. Task 1 refusal row `missing content type` sends `{"url":"https://example.com/a"}` and an empty server array.
5. `{"url":1}` is 400, not the stub. Task 1 refusal row `url not a string` pins that a number is not coerced into a string.

## File map

- Modify: `packages/web-app/composer.json` and `packages/web-app/composer.lock` — `symfony/serializer` and `symfony/validator` at `8.1.*`.
- Modify: `packages/web-app/symfony.lock` and any Flex files under `packages/web-app/config/packages/`, only if Flex writes them.
- Create: `packages/web-app/src/Api/Dto/CreateArchiveBody.php` — the body type.
- Create: `packages/web-app/src/Api/Controller/CreateArchiveController.php` — the HTTP adapter.
- Delete: `packages/web-app/src/Controller/Api/CreateArchiveController.php`.
- Modify: `packages/web-app/config/routes.yaml` — import path and namespace.
- Modify: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php` — accepted rows and refusal rows.
- Modify: `packages/web-app/doc/features/0002-server/specs/EmptyRoute.md` — the move is no longer waiting.
- Modify: `packages/web-app/README.md` — the curl sends JSON.
- Modify: `packages/web-app/doc/features/0002-server/feature.md` — slice 4 status, at the start and at the end.

---

### Task 1: Map the archive body

**Files:**
- Modify: `packages/web-app/composer.json`
- Modify: `packages/web-app/composer.lock`
- Modify: `packages/web-app/symfony.lock` (if Flex updates it)
- Modify: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`
- Create: `packages/web-app/src/Api/Dto/CreateArchiveBody.php`
- Create: `packages/web-app/src/Api/Controller/CreateArchiveController.php`
- Delete: `packages/web-app/src/Controller/Api/CreateArchiveController.php`
- Modify: `packages/web-app/config/routes.yaml`
- Modify: `packages/web-app/doc/features/0002-server/specs/EmptyRoute.md`
- Modify: `packages/web-app/doc/features/0002-server/feature.md`
- Test: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`

**Interfaces:**
- Consumes: `Yumo\Eleanor\Tests\Integration\CreateArchiveRouteTest` and `KernelTest::test_boot_sees_test_environment(): void`. `phpunit.xml.dist` already forces the test environment.
- Produces: `Yumo\Eleanor\Api\Controller\CreateArchiveController::create(CreateArchiveBody $body): JsonResponse`. `Yumo\Eleanor\Api\Dto\CreateArchiveBody::__construct(?string $url = null)`. Route name `create_archive`. No service id is added.

- [ ] **Step 1: Mark slice 4 in progress**

In `packages/web-app/doc/features/0002-server/feature.md`, set the Payload and mapper status cell to `In progress`.

- [ ] **Step 2: Write the failing test**

In `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`, replace `successfulPostProvider` and `test_post_returns_the_empty_route_stub` with the accepted provider and test. Add the refused provider and test after that test. Leave the class docblock, the imports, `test_head_is_method_not_allowed`, `test_get_is_method_not_allowed`, `test_post_with_trailing_slash_misses_the_route`, `test_unprefixed_path_does_not_match_create_archive`, `matchedRoute`, and `responseBody` as they are, including `getInternalResponse()`.

Accepted rows expect route `create_archive`, 200, `application/json`, and `{"ok":true}`. Refusal rows expect the status and `application/json`. They do not expect a body string, a route name, or an exception class.

Do not add rows for a `url` of spaces, a string that is not a usable URL, `application/x-www-form-urlencoded`, or an empty `application/json` body. Those share the rows below.

```php
/**
 * @return iterable<string, array{string, array<string, string>, string}>
 */
public static function acceptedPostProvider(): iterable
{
    yield 'string url' => ['/api/archives?ignored=1', ['CONTENT_TYPE' => 'application/json; charset=utf-8'], '{"url":"https://example.com/a"}'];
    yield 'empty url' => ['/api/archives', ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], '{"url":""}'];
    yield 'extra property' => ['/api/archives', ['CONTENT_TYPE' => 'application/json'], '{"url":"https://example.com/a","extra":1}'];
}

/**
 * @param array<string, string> $server
 */
#[DataProvider('acceptedPostProvider')]
public function test_accepted_post_returns_the_empty_route_stub(string $uri, array $server, string $content): void
{
    $client = static::createClient();
    $client->request('POST', $uri, [], [], $server, $content);

    self::assertRouteSame('create_archive');
    self::assertResponseStatusCodeSame(200);
    self::assertResponseHeaderSame('content-type', 'application/json');
    self::assertSame('{"ok":true}', $this->responseBody($client->getInternalResponse()));
}

/**
 * @return iterable<string, array{int, array<string, string>, ?string}>
 */
public static function refusedPostProvider(): iterable
{
    yield 'malformed json' => [400, ['CONTENT_TYPE' => 'application/json'], '{'];
    yield 'missing url' => [400, ['CONTENT_TYPE' => 'application/json'], '{}'];
    yield 'url not a string' => [400, ['CONTENT_TYPE' => 'application/json'], '{"url":1}'];
    yield 'json value not the object' => [400, ['CONTENT_TYPE' => 'application/json'], '[]'];
    yield 'missing content type' => [415, [], '{"url":"https://example.com/a"}'];
    yield 'text plain' => [415, ['CONTENT_TYPE' => 'text/plain'], 'not-json'];
}

/**
 * @param array<string, string> $server
 */
#[DataProvider('refusedPostProvider')]
public function test_refused_post_returns_json_status(int $status, array $server, ?string $content): void
{
    $client = static::createClient();
    $client->request('POST', '/api/archives', [], [], $server, $content);

    self::assertResponseStatusCodeSame($status);
    self::assertResponseHeaderSame('content-type', 'application/json');
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./bin/test web-app -- --filter CreateArchiveRouteTest`
Expected: exit code non-zero. A refusal row fails because the status is 200.

- [ ] **Step 4: Require serializer and validator**

From the repo root:

```bash
./bin/composer web-app require 'symfony/serializer:8.1.*' 'symfony/validator:8.1.*'
```

`require` gains `"symfony/serializer": "8.1.*"` and `"symfony/validator": "8.1.*"`. It gains no other name. `require-dev` gains no name. Keep a Flex recipe file Flex writes. Do not add a `config/packages` file by hand.

- [ ] **Step 5: Move the controller and map the body**

Delete `packages/web-app/src/Controller/Api/CreateArchiveController.php`.

Create `packages/web-app/src/Api/Dto/CreateArchiveBody.php`. The class is `final`. `#[Exclude]` is on the class. The only method is the constructor:

```php
public function __construct(
    #[NotNull]
    public ?string $url = null,
)
```

`Exclude` is `Symfony\Component\DependencyInjection\Attribute\Exclude`. `NotNull` is `Symfony\Component\Validator\Constraints\NotNull`. JSDoc on the class says it is the body of the archive command and not an archive, an article, or a URL pointer. JSDoc on the constructor says null is refused and an empty string is kept.

Create `packages/web-app/src/Api/Controller/CreateArchiveController.php`. The class is `final`. It has no parent and no constructor. The only method is:

```php
#[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
public function create(
    #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
    CreateArchiveBody $body,
): JsonResponse
```

`Route` is `Symfony\Component\Routing\Attribute\Route`. `MapRequestPayload` is `Symfony\Component\HttpKernel\Attribute\MapRequestPayload`. Leave `$body` unread. Return `JsonResponse::fromJsonString('{"ok":true}')`. JSDoc on the class says it is the HTTP adapter for the empty archive route. JSDoc on `create` says it returns the empty-route stub and does not read the mapped body.

In `packages/web-app/config/routes.yaml`, change only the resource path and namespace:

```yaml
        path: ../src/Api/Controller/
        namespace: Yumo\Eleanor\Api\Controller
```

In `packages/web-app/doc/features/0002-server/specs/EmptyRoute.md`, replace the sentence `The finished plan and the application code still use the old directory until that move is implemented.` with `The payload slice moved the class. The finished empty-route plan stays the record of the code written then.`

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./bin/test web-app -- --filter CreateArchiveRouteTest`
Expected: exit code 0.

Run: `./bin/test web-app`
Expected: exit code 0. `test_boot_sees_test_environment` still passes.

Run: `./bin/quality web-app`
Expected: exit code 0.

- [ ] **Step 7: Commit**

```bash
git add packages/web-app/composer.json packages/web-app/composer.lock packages/web-app/src/Api/Dto/CreateArchiveBody.php packages/web-app/src/Api/Controller/CreateArchiveController.php packages/web-app/src/Controller/Api/CreateArchiveController.php packages/web-app/config/routes.yaml packages/web-app/tests/Integration/CreateArchiveRouteTest.php packages/web-app/doc/features/0002-server/specs/EmptyRoute.md packages/web-app/doc/features/0002-server/feature.md
git commit -m "$(cat <<'EOF'
Map the archive body before the empty-route stub.

EOF
)"
```

Stage `symfony.lock` and any Flex file under `packages/web-app/config/packages/` only if it changed. Do not stage `vendor/` or `var/`.

---

### Task 2: Document the JSON call

**Files:**
- Modify: `packages/web-app/README.md`
- Modify: `packages/web-app/doc/features/0002-server/feature.md`

**Interfaces:**
- Consumes: `POST /api/archives` from Task 1. The server command stays `./bin/docker-serve web-app`. The base URL stays `http://127.0.0.1:8000`.
- Produces: the README curl a person runs against that server. It is a manual check, not a test.

- [ ] **Step 1: Update the curl**

In `packages/web-app/README.md`, replace the curl under `## Examples`. Leave that heading, the server command, and the sentence `The response body is {"ok":true}`.

```bash
curl -X POST http://127.0.0.1:8000/api/archives \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/a"}'
```

- [ ] **Step 2: Confirm the documented call**

Run: `rg -n "Content-Type: application/json" packages/web-app/README.md`
Expected: one match, in the curl.

Run: `rg -n --fixed-strings '{"url":"https://example.com/a"}' packages/web-app/README.md`
Expected: one match.

Run: `rg -n --fixed-strings '{"ok":true}' packages/web-app/README.md`
Expected: one match.

Run: `rg -n "docker-serve web-app" packages/web-app/README.md`
Expected: one match. The command is unchanged.

- [ ] **Step 3: Record slice 4 done**

In `packages/web-app/doc/features/0002-server/feature.md`:

- Payload and mapper status cell: `Done`.
- Next slice stays `3 — Logging. Not started.`
- Application code cell: `Yumo\Eleanor\Kernel` boots. `POST /api/archives` is `create_archive`. The controller is `Yumo\Eleanor\Api\Controller\CreateArchiveController` in `src/Api/Controller/`. The body type is `Yumo\Eleanor\Api\Dto\CreateArchiveBody`. The action maps that body and returns the empty-route stub. The boot test is `tests/Integration/KernelTest.php`.
- Specs and plans: add `[payload and mapper](plans/2026-10-13-payload-and-mapper.md)` beside the empty-route plan.

- [ ] **Step 4: Commit**

```bash
git add packages/web-app/README.md packages/web-app/doc/features/0002-server/feature.md
git commit -m "$(cat <<'EOF'
Document the JSON body on the archive curl.

EOF
)"
```
