# JSON routes sit under a stateless /api import

Status: accepted. The [empty-route spec](../features/server/specs/EmptyRoute.md) adopts this.

JSON controllers live in `src/Controller/Api/` (`Yumo\Eleanor\Controller\Api`). One YAML import loads that directory. The import sets `prefix: /api`, `trailing_slash_on_root: false`, and `stateless: true`. Each action declares the path after the prefix, the HTTP methods, and the format. `POST /api/archives` is path `/archives`, name `create_archive`, methods `POST`, format `json`. There is no route alias.

`routing.controllers` loads every class tagged `routing.controller`, with no directory filter. [AttributeServicesLoader](https://github.com/symfony/routing/blob/v8.1.8/Loader/AttributeServicesLoader.php) supports only that resource name. Leaving it next to the API import would register the same action twice: `/archives` and `/api/archives`, under one name. The empty-route slice removes that catch-all. An HTML import, without this prefix and without `stateless`, is added with the first HTML page. The catch-all does not come back.

A class-level `#[Route('/api')]` joins path strings. A trailing slash on that prefix stores `/api//archives`. Putting `/api` on every action would repeat the prefix and would not make the group stateless. `framework.router` has no path prefix. [Route.md](../features/server/research/Route.md) records those mechanisms.

`trailing_slash_on_root: false` does not change `/archives`. With this prefix the path is `/api/archives` either way. The flag keeps a future import whose path is `/` at `/api` instead of `/api/`.

The route matches before validation. `MapRequestPayload` is the payload slice, not this import.
