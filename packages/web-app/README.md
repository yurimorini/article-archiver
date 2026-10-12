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

The response body is `{"ok":true}`.

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
