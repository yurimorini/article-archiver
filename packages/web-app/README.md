# Eleanor

## Temporary server

Start PHP's built-in server in the development image:

```bash
./bin/docker-serve web-app
```

The server listens on `http://127.0.0.1:8000`.

## Examples

```bash
curl -X POST http://127.0.0.1:8000/api/archives \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/a"}'
```

A new archive answers 201 with `{"url":"https://example.com/a","title":"..."}`. A second POST of the same URL answers 200 with the same body and does not fetch the page again, but only inside one PHP process.

The archive belongs to the owner whose email is the `eleanor.owner_email` parameter in `config/services.yaml`. Change it there. Until the SQLite slice, archives live in memory and each request of the temporary server starts empty, so the curl above answers 201 every time. Kernel tests prove the 200.

A failure answers `application/json` with `code` and `message`. A body whose URL is not valid:

```bash
curl -i -X POST http://127.0.0.1:8000/api/archives \
  -H 'Content-Type: application/json' \
  -d '{"url":"not-a-url"}'
```

answers 400 with:

```json
{"code":"url_syntax","message":"The URL is not valid."}
```

The status is the family of the failure. `code` is the cause. `message` is the sentence a person reads. The `X-Request-Id` header carries the request id.

## API description

The API description is `doc/openapi.yaml`. A test fails when it drifts from the code. Regenerate it from the repository root with:

```bash
./bin/composer web-app openapi
```

## Tests

From the repository root, PHPUnit for this package runs inside the development image:

```bash
./bin/test web-app
```

Pass PHPUnit arguments after `--`:

```bash
./bin/test web-app -- --filter CreateArchiveRouteTest
```

If the image is missing, build it and install dependencies first:

```bash
./bin/docker-build
./bin/composer-install web-app
```
