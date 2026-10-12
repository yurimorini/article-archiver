# Empty route

Module design for [empty-route.md](../empty-route.md). The decisions in that contract are closed. This slice adds no glossary term. It adopts [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md) and [adr/0006](../../../adr/0006-kernel-tests-live-in-integration.md).

## Module

`Yumo\Eleanor\Controller\Api\CreateArchiveController` is the HTTP adapter for this slice. The action is `create`. The class does not extend `AbstractController`. It has no constructor dependency and the action has no parameter. It does not read the body, map a payload, touch the session, call an archive service, or fetch a page.

The class is a service through the existing `Yumo\Eleanor\` resource. This slice adds no service definition.

One import loads that namespace. The import sets prefix `/api`, `trailing_slash_on_root` false, and `stateless` true. It does not set methods or format. The route entry that loads `routing.controllers` is removed. It is not restored when a later HTML import is added. This slice does not add the HTML import.

The action declares path `/archives`, name `create_archive`, method POST, and format `json`. It has no alias. POST and the format are declared on the action. The finished path is `/api/archives`, with no trailing slash and no version segment. The query string is not part of the match.

## Response

Every POST on `/api/archives` returns 200 and the JSON object `{"ok":true}` with content type `application/json`. The action returns that as a `JsonResponse`.

That return covers every body: absent, not JSON, a JSON URL pointer of any JSON type, a JSON object without `url`, a JSON array, and a JSON scalar. It covers a missing request content type, a non-JSON content type, an `Accept` that is not JSON, and a query string on `/api/archives`.

The 200 is the empty-route stub. It is not the stored-archive 200, and it is not the 201 of a new archive.

## Errors

Only POST is the command on `/api/archives`. Any other verb, including HEAD, receives 405 from the framework (`MethodNotAllowedHttpException`). The body of that 405 stays the framework's.

A request path other than `/api/archives` does not match `create_archive`. `POST /api/archives/` misses this route and receives 404 (`NotFoundHttpException`). The framework's trailing-slash redirect applies to GET and HEAD, so this POST is not redirected. `/archives` without the prefix is not this route.

A wrong content type is not 415. An unreadable body is not 400. This slice adds no Eleanor exception type. The skeleton's dev error route stays. This slice adds no owner email, bearer token, database path, or archive directory.

## Temporary server

`./bin/docker-serve web-app` starts PHP's built-in server in the foreground, inside the existing development image. The launcher reuses the image, mount, working directory, and user of `bin/docker-run`. It publishes `127.0.0.1:8000:8000`. Inside the container the process listens on `0.0.0.0:8000`, with document root `public`. A router script forwards each path that is not a file to the front controller `public/index.php`.

The host bind is `127.0.0.1`, so the port is not reachable from the LAN. A package other than `web-app` fails. The port stays 8000. The development image is unchanged. The Symfony CLI is not installed.

`packages/web-app/README.md` documents how to contact the server: the command and the base URL `http://127.0.0.1:8000`. An examples section lists callable paths. This slice adds one example, `POST /api/archives`, and the expected body `{"ok":true}`.

## Test

One HTTP test, in the web-app suite, is the automated seam. It is a `WebTestCase` in `Yumo\Eleanor\Tests\Integration`, a flat file under `tests/Integration/`. The client sends the request through the kernel in the test environment. It reads the status, the content type, the body, and the matched route name. It does not call the action as a function, open a socket, start `docker-serve`, or read the text of the route attribute.

The successful cases are a POST with no body, a POST with a non-JSON body, a POST with a JSON URL pointer, and a POST whose `url` is not a string. Each asserts route `create_archive`, status 200, content type `application/json`, and body `{"ok":true}`.

HEAD on `/api/archives` asserts 405. A POST on `/archives` does not match `create_archive`. The other request shapes in the response section are the same return path and are not separate assertions.

The kernel boot test moves into the same directory and namespace. Its assertion stays: the environment is `test`. The dev dependency added for the client is `symfony/browser-kit` on `8.1.*`. `symfony/dom-crawler` arrives because browser-kit requires it. The test pack and `symfony/css-selector` are not added.

`bin/docker-serve` has a launcher test in `bin/tests/`, on the same pattern as `docker-run`. The test records the `docker run` arguments and checks the publish `127.0.0.1:8000:8000` and `php -S 0.0.0.0:8000`. It does not start the server and does not curl. The curl in the README is a manual check. An agent-driven browser is not a seam.

The package quality command exiting 0 is an acceptance check. It is not a second test.

## Out of scope

Request logging. Refusing a content type, rejecting a body, and mapping the payload. Reading a URL pointer, fetching a page, purifying an article, and storing an archive. The success body `url` and `title`, the 201 for a new archive, and the 200 for an archive the owner already has. The firewall, the bearer token, and the owner email. PHP-FPM, the reverse proxy, and the distributable image. HTML pages, CORS, HTTP caching, CSRF, a version segment, and an idempotency key. Request size limits and other configuration beyond this route. A new glossary term.
