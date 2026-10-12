# Empty Route Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/archives` returns 200 `{"ok":true}` as `application/json` for any body, proved by one `WebTestCase`, with a loopback temporary server documented in the web-app README.

**Architecture:** One attribute route on `Yumo\Eleanor\Controller\Api\CreateArchiveController::create` is loaded by a single stateless `/api` import. The catch-all `routing.controllers` import is removed and not replaced. The action returns a `JsonResponse` and does not read the request. `bin/docker-serve` starts PHP's built-in server in the existing development image, bound to loopback port 8000, with a router script that forwards non-files to `public/index.php`.

**Tech Stack:** PHP 8.5, Symfony 8.1, existing PHPUnit 11, `symfony/browser-kit` `8.1.*`, `bin/docker-run`, `bin/test`, `bin/quality`.

**Spec:** `packages/web-app/doc/features/server/specs/EmptyRoute.md`

## Global Constraints

- Controller is `Yumo\Eleanor\Controller\Api\CreateArchiveController`. Action is `create`. It does not extend `AbstractController`. It has no constructor dependency. The action has no parameter.
- The action does not read the body, map a payload, touch the session, call an archive service, or fetch a page. It adds no Eleanor exception type.
- The class is a service through the existing `Yumo\Eleanor\` resource. This slice adds no service definition. Do not edit `packages/web-app/config/services.yaml`.
- One import loads `Yumo\Eleanor\Controller\Api` with prefix `/api`, `trailing_slash_on_root` false, and `stateless` true. The import does not set methods or format.
- The `routing.controllers` entry is removed. It is not restored. This slice does not add an HTML import.
- The action path is `/archives`, name `create_archive`, method POST, format `json`. It has no alias. POST and the format are declared on the action.
- The finished path is `/api/archives`, with no trailing slash and no version segment. The query string is not part of the match.
- Every POST on `/api/archives` returns 200 and `{"ok":true}` with content type `application/json`, as a `JsonResponse`. That 200 is the empty-route stub.
- `new JsonResponse(['ok' => true])` is the wrong body. `JsonResponse` defaults to encoding options that hex-escape quotes. Return `JsonResponse::fromJsonString('{"ok":true}')`.
- Only POST is the command. Any other verb, including HEAD, receives 405. The 405 body stays the framework's. Do not assert that body.
- `POST /api/archives/` misses this route and is 404. It is not redirected. `POST /archives` is not this route.
- A wrong content type is not 415. An unreadable body is not 400. The action has no parameter, so it cannot parse the body.
- Dev dependency added: `symfony/browser-kit` on `8.1.*`. `symfony/dom-crawler` may arrive in the lock because browser-kit requires it. Do not add `symfony/test-pack` or `symfony/css-selector`. No other package is added.
- The kernel boot test moves to `tests/Integration/` and namespace `Yumo\Eleanor\Tests\Integration`. Its assertion stays: the environment is `test`. Do not change `packages/web-app/phpunit.xml.dist`.
- The HTTP test is one `WebTestCase` in that same directory. It sends the request through the kernel. It does not call the action as a function, open a socket, start `docker-serve`, or read the route attribute text.
- `./bin/docker-serve web-app` is PHP's built-in server in the foreground, inside the existing image. It reuses the image, mount, working directory, and user of `bin/docker-run`. Publish `127.0.0.1:8000:8000`. Inside the container the process is `php -S 0.0.0.0:8000` with document root `public`. Do not change `Dockerfile`. Do not install the Symfony CLI. The port stays 8000. A package other than `web-app` fails.
- The skeleton's dev error route stays. Do not edit `packages/web-app/config/routes/framework.yaml`. Add no owner email, bearer token, database path, or archive directory.
- No glossary term. `./bin/quality web-app` exiting 0 is an acceptance check, not a second test.

## Review Focus

1. `POST /api/archives/` is 404, not a redirect onto `/api/archives`. Task 2 `test_post_with_trailing_slash_misses_the_route` pins status 404 and route not `create_archive`, with redirects not followed.
2. `GET /api/archives` is 405. HEAD 405 still passes if GET is allowed. Task 2 `test_get_is_method_not_allowed` pins 405.
3. `Accept: text/html` still returns `application/json` and `{"ok":true}`. The client strips `Accept` when the test does not set it. Task 2 dataset `no body` sends `HTTP_ACCEPT` `text/html`.
4. A publish of `0.0.0.0:8000:8000` would open the port on the LAN. Task 3 asserts the recorded `docker run` arguments contain `127.0.0.1:8000:8000` and do not contain `0.0.0.0:8000:8000`.
5. `Content-Type: text/plain` with a non-JSON body is 200, not 415. Task 2 dataset `non-json body` sends that header and the body `not-json`.

## File map

- Modify: `packages/web-app/tests/KernelTest.php` — move to `packages/web-app/tests/Integration/KernelTest.php`. Namespace becomes `Yumo\Eleanor\Tests\Integration`.
- Modify: `packages/web-app/composer.json`, `packages/web-app/composer.lock`, and `packages/web-app/symfony.lock` if Flex writes it — dev dependency `symfony/browser-kit` `8.1.*` only.
- Create: `packages/web-app/src/Controller/Api/CreateArchiveController.php` — the HTTP adapter.
- Modify: `packages/web-app/config/routes.yaml` — replace `routing.controllers` with the `/api` import.
- Create: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php` — the HTTP seam.
- Create: `bin/lib/container.sh` — the `docker run` flags now built inside `bin/docker-run`.
- Modify: `bin/docker-run` — call that function. The arguments it passes stay the same.
- Modify: `bin/tests/launcher_test.sh` — copy `container.sh` in the two real `docker-run` tests, and add the `docker-serve` tests.
- Create: `bin/docker-serve` — foreground server for `web-app` only.
- Create: `packages/web-app/router.php` — built-in-server router. It lives outside `public` so it is not a URL.
- Create: `packages/web-app/README.md` — command, base URL, one curl.
- Modify: `packages/web-app/doc/features/server/feature.md` — slice 2 status, at the start and at the end.

---

### Task 1: Move the kernel boot test

**Files:**
- Create: `packages/web-app/tests/Integration/KernelTest.php`
- Delete: `packages/web-app/tests/KernelTest.php`
- Modify: `packages/web-app/doc/features/server/feature.md`

**Interfaces:**
- Consumes: nothing from an earlier task.
- Produces: `Yumo\Eleanor\Tests\Integration\KernelTest::test_boot_sees_test_environment(): void`. Later HTTP tests use the same namespace and directory. The assertion stays `test`.

- [ ] **Step 1: Mark slice 2 in progress**

In `packages/web-app/doc/features/server/feature.md`, set the Empty route status cell to `In progress`.

- [ ] **Step 2: Move the boot test**

Move `packages/web-app/tests/KernelTest.php` to `packages/web-app/tests/Integration/KernelTest.php`. Change only the namespace to `Yumo\Eleanor\Tests\Integration`. Leave the class, the method, and the assertion as they are.

- [ ] **Step 3: Run the web-app suite**

Run: `./bin/test web-app`
Expected: exit code 0. `test_boot_sees_test_environment` passes.

- [ ] **Step 4: Commit**

```bash
git add packages/web-app/tests/Integration/KernelTest.php packages/web-app/tests/KernelTest.php packages/web-app/doc/features/server/feature.md
git commit -m "$(cat <<'EOF'
Move the kernel boot test into tests/Integration.

EOF
)"
```

---

### Task 2: Answer POST /api/archives with the stub

**Files:**
- Modify: `packages/web-app/composer.json`
- Modify: `packages/web-app/composer.lock`
- Modify: `packages/web-app/symfony.lock` (if Flex updates it)
- Create: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`
- Create: `packages/web-app/src/Controller/Api/CreateArchiveController.php`
- Modify: `packages/web-app/config/routes.yaml`
- Test: `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`

**Interfaces:**
- Consumes: `Yumo\Eleanor\Tests\Integration` from Task 1. `phpunit.xml.dist` already forces `APP_ENV` and `APP_SECRET` to `test`.
- Produces: `Yumo\Eleanor\Controller\Api\CreateArchiveController::create(): JsonResponse`. Route name `create_archive`. Finished path `/api/archives`. No service id is added.

- [ ] **Step 1: Require browser-kit**

From the repo root:

```bash
./bin/composer web-app require --dev 'symfony/browser-kit:8.1.*'
```

`require-dev` gains `"symfony/browser-kit": "8.1.*"`. It does not gain `symfony/test-pack` or `symfony/css-selector`. `symfony/dom-crawler` may appear in `composer.lock` only.

- [ ] **Step 2: Write the failing test**

Create `packages/web-app/tests/Integration/CreateArchiveRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves POST /api/archives through the kernel.
 */
final class CreateArchiveRouteTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, ?string}>
     */
    public static function successfulPostProvider(): iterable
    {
        yield 'no body' => ['/api/archives', ['HTTP_ACCEPT' => 'text/html'], null];
        yield 'non-json body' => ['/api/archives', ['CONTENT_TYPE' => 'text/plain'], 'not-json'];
        yield 'json url pointer' => ['/api/archives?ignored=1', ['CONTENT_TYPE' => 'application/json'], '{"url":"https://example.com/a"}'];
        yield 'url not a string' => ['/api/archives', ['CONTENT_TYPE' => 'application/json'], '{"url":1}'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('successfulPostProvider')]
    public function test_post_returns_the_empty_route_stub(string $uri, array $server, ?string $content): void
    {
        $client = static::createClient();
        $client->request('POST', $uri, [], [], $server, $content);

        self::assertRouteSame('create_archive');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('{"ok":true}', $this->responseBody($client->getResponse()));
    }

    public function test_head_is_method_not_allowed(): void
    {
        $client = static::createClient();
        $client->request('HEAD', '/api/archives');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_get_is_method_not_allowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/archives');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_post_with_trailing_slash_misses_the_route(): void
    {
        $client = static::createClient();
        $client->followRedirects(false);
        $client->request('POST', '/api/archives/');

        self::assertResponseStatusCodeSame(404);
        self::assertNotSame('create_archive', $this->matchedRoute($client->getRequest()));
    }

    public function test_unprefixed_path_does_not_match_create_archive(): void
    {
        $client = static::createClient();
        $client->request('POST', '/archives');

        self::assertNotSame('create_archive', $this->matchedRoute($client->getRequest()));
    }

    private function matchedRoute(object $request): mixed
    {
        if (!$request instanceof Request) {
            self::fail('The client request must be an HTTP request.');
        }

        return $request->attributes->get('_route');
    }

    private function responseBody(object $response): string
    {
        if (!$response instanceof Response) {
            self::fail('The client response must be a browser response.');
        }

        return $response->getContent();
    }
}
```

Do not assert the 405 body. Do not assert the text of the route attribute. The `no body` dataset pins `Accept: text/html`. The `non-json body` dataset pins `text/plain` is not 415. The `json url pointer` dataset pins the query string.

- [ ] **Step 3: Run the test to verify it fails**

Run: `./bin/test web-app -- --filter CreateArchiveRouteTest`
Expected: FAIL. Output contains `create_archive`.

- [ ] **Step 4: Add the controller and replace the controller import**

Create `packages/web-app/src/Controller/Api/CreateArchiveController.php`. The class is `final`. It has no parent and no constructor. The only method is:

```php
#[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
public function create(): JsonResponse
```

`Route` is `Symfony\Component\Routing\Attribute\Route`. Return `JsonResponse::fromJsonString('{"ok":true}')`. JSDoc on the class says it is the HTTP adapter for the empty archive route. JSDoc on `create` says it returns the empty-route stub and does not read the request.

Replace `packages/web-app/config/routes.yaml` with:

```yaml
# yaml-language-server: $schema=../vendor/symfony/routing/Loader/schema/routing.schema.json

# API controllers only. The catch-all routing.controllers import stays removed.
api_controllers:
    resource:
        path: ../src/Controller/Api/
        namespace: Yumo\Eleanor\Controller\Api
    type: attribute
    prefix: /api
    trailing_slash_on_root: false
    stateless: true
```

Do not set `methods`, `format`, or `name_prefix` on the import. Do not add a service definition.

- [ ] **Step 5: Run the test to verify it passes**

Run: `./bin/test web-app -- --filter CreateArchiveRouteTest`
Expected: exit code 0.

Run: `./bin/test web-app`
Expected: exit code 0. The boot test still passes.

Run: `./bin/quality web-app`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add packages/web-app/composer.json packages/web-app/composer.lock packages/web-app/symfony.lock packages/web-app/src/Controller/Api/CreateArchiveController.php packages/web-app/config/routes.yaml packages/web-app/tests/Integration/CreateArchiveRouteTest.php
git commit -m "$(cat <<'EOF'
Answer POST /api/archives with the empty-route stub.

EOF
)"
```

Stage `symfony.lock` only if it changed. Do not stage `vendor/` or `var/`.

---

### Task 3: Serve Eleanor on loopback

**Files:**
- Create: `bin/lib/container.sh`
- Modify: `bin/docker-run`
- Modify: `bin/tests/launcher_test.sh`
- Create: `bin/docker-serve`
- Create: `packages/web-app/router.php`
- Create: `packages/web-app/README.md`
- Modify: `packages/web-app/doc/features/server/feature.md`
- Test: `bin/tests/launcher_test.sh`

**Interfaces:**
- Consumes: nothing from the controller. The README curl calls the route Task 2 registered.
- Produces: `./bin/docker-serve web-app` from the repository root. `container_fill_run_args` fills the global array `run_args` with the flags `bin/docker-run` already passed. `docker-serve` adds `-p 127.0.0.1:8000:8000` and the command `php -S 0.0.0.0:8000 -t public router.php`.

- [ ] **Step 1: Write the failing launcher test**

In `bin/tests/launcher_test.sh`, add tests on the fixture pattern of `test_docker_run_sets_package_workdir_and_composer_home`. Copy `bin/docker-serve`, `bin/lib/container.sh`, and `bin/lib/projects.sh`. The projects file lists `web-app` at `packages/web-app`. Use `install_fake_docker`.

`test_docker_serve_publishes_loopback_and_starts_php`: `./bin/docker-serve web-app` exits 0. The recorded `docker run` line contains `-w /workspace/packages/web-app`, `log-read-php`, `-p 127.0.0.1:8000:8000`, and `php -S 0.0.0.0:8000 -t public router.php`. It does not contain `0.0.0.0:8000:8000`. It does not contain `-d`.

`test_docker_serve_usage_unknown_package_and_missing_image`, on the pattern of `test_docker_run_usage_and_unknown_package_and_missing_image`:

- No arguments: exit 1, stderr `Usage: ./bin/docker-serve web-app`, docker log empty.
- Argument `article-reader`, with that package listed in projects: exit 1, stderr `docker-serve only serves web-app`, docker log empty.
- Argument `web-app` when `DOCKER_IMAGE_MISSING=1`: exit 1, stderr `Docker image 'log-read-php' not found. Build it with: ./bin/docker-build`, docker log empty.

Call both functions from the bottom of the file, with the other tests. The test does not start the server and does not curl.

- [ ] **Step 2: Run the launcher test to verify it fails**

Run: `bash bin/tests/launcher_test.sh`
Expected: exit code non-zero. `bin/docker-serve` is missing, so the copy fails.

- [ ] **Step 3: Share the docker run flags and add the server**

Move the `run_args` construction from `bin/docker-run` into `bin/lib/container.sh` as `container_fill_run_args`. Arguments are the repo root and the package path relative to that root. The function writes the global array `run_args`. It includes the mount, workdir, `COMPOSER_HOME`, and the same rootless-or-user branch `docker-run` has now. It does not add the image name, the command, or a publish port.

`bin/docker-run` sources `bin/lib/container.sh`, checks the image as it does now, calls `container_fill_run_args`, then `exec docker run "${run_args[@]}" "${IMAGE_NAME}" "$@"`. The arguments a caller of `docker-run` sees do not change.

In `test_docker_run_sets_package_workdir_and_composer_home` and `test_docker_run_usage_and_unknown_package_and_missing_image`, copy `bin/lib/container.sh` into the fixture next to `projects.sh`.

`bin/docker-serve` takes exactly one argument. Usage text is `Usage: ./bin/docker-serve web-app`. Any name other than `web-app` prints `docker-serve only serves web-app` and exits 1, before Docker. `web-app` resolves through `projects_path_for`. The missing-image message matches `docker-run`. After `container_fill_run_args`:

```bash
exec docker run -p 127.0.0.1:8000:8000 "${run_args[@]}" "${IMAGE_NAME}" php -S 0.0.0.0:8000 -t public router.php
```

No `-d`. Working directory stays the package directory, so `public` and `router.php` are relative to `packages/web-app`.

Create `packages/web-app/router.php`. The runtime loads `$_SERVER['SCRIPT_FILENAME']`, which the built-in server sets to this router. An existing file returns false so PHP serves the file. Any other path points `SCRIPT_FILENAME` at the front controller and requires it:

```php
<?php

declare(strict_types=1);

$file = ($_SERVER['DOCUMENT_ROOT'] ?? '') . ($_SERVER['SCRIPT_NAME'] ?? '');
if (is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';

require $_SERVER['SCRIPT_FILENAME'];
```

- [ ] **Step 4: Run the launcher test to verify it passes**

Run: `bash bin/tests/launcher_test.sh`
Expected: exit code 0. Output ends with `launcher tests passed`.

- [ ] **Step 5: Document the server**

Create `packages/web-app/README.md`. Commands are run from the repository root.

```markdown
# Eleanor

## Temporary server

Start PHP's built-in server in the development image:

```bash
./bin/docker-serve web-app
```

The server listens on `http://127.0.0.1:8000`.

## Examples

```bash
curl -X POST http://127.0.0.1:8000/api/archives
```

The response body is `{"ok":true}`.
```

- [ ] **Step 6: Acceptance checks**

Run: `./bin/quality web-app`
Expected: exit code 0.

Run: `./bin/test web-app`
Expected: exit code 0.

- [ ] **Step 7: Record slice 2 done**

In `packages/web-app/doc/features/server/feature.md`:

- Empty route status cell: `Done`.
- Next slice: `3 — Logging. Not started.`
- Application code: `Yumo\Eleanor\Kernel` boots. `POST /api/archives` is `create_archive`. The boot test is `tests/Integration/KernelTest.php`.
- Specs and plans: link `specs/EmptyRoute.md` and `plans/2026-10-12-empty-route.md` beside the kernel plan.

- [ ] **Step 8: Commit**

```bash
git add bin/lib/container.sh bin/docker-run bin/docker-serve bin/tests/launcher_test.sh packages/web-app/router.php packages/web-app/README.md packages/web-app/doc/features/server/feature.md
git commit -m "$(cat <<'EOF'
Serve Eleanor on loopback with PHP's built-in server.

EOF
)"
```
