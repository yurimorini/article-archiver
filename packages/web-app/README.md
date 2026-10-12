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
