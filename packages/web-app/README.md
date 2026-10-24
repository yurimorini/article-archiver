# Eleanor

## Temporary server

Start PHP's built-in server in the development image, from the repository root:

```bash
packages/web-app/bin/serve
```

The server listens on `http://127.0.0.1:8000`.

## Bearer secret

Every call to a route under `/api` needs the secret in this header:

```
Authorization: Bearer <secret>
```

The secret is the environment variable `ELEANOR_BEARER_TOKEN`. The Owner sets it in `.env.local`, which stays out of git. The committed `.env` does not hold it.

```bash
echo 'ELEANOR_BEARER_TOKEN=change-me' >> packages/web-app/.env.local
```

A valid secret authenticates the Owner. The Owner is still the email in the `eleanor.owner_email` parameter.

## Examples

The examples read the secret from the shell. Export the same value as `.env.local`:

```bash
export ELEANOR_BEARER_TOKEN=change-me
```

```bash
curl -X POST http://127.0.0.1:8000/api/archives \
  -H "Authorization: Bearer $ELEANOR_BEARER_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/a"}'
```

A new archive answers 201 with `{"url":"https://example.com/a","title":"..."}`. A second POST of the same URL answers 200 with the same body and does not fetch the page again, but only inside one PHP process.

The archive belongs to the owner whose email is the `eleanor.owner_email` parameter in `config/services.yaml`. Change it there. Until the SQLite slice, archives live in memory and each request of the temporary server starts empty, so the curl above answers 201 every time. Kernel tests prove the 200.

A failure answers `application/json` with `code` and `message`. A body whose URL is not valid:

```bash
curl -i -X POST http://127.0.0.1:8000/api/archives \
  -H "Authorization: Bearer $ELEANOR_BEARER_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"url":"not-a-url"}'
```

answers 400 with:

```json
{"code":"url_syntax","message":"The URL is not valid."}
```

The status is the family of the failure. `code` is the cause. `message` is the sentence a person reads. The `X-Request-Id` header carries the request id.

## Tests

From the repository root, PHPUnit for this package runs inside the development image:

```bash
packages/web-app/bin/run test
```

Pass PHPUnit arguments after `--`:

```bash
packages/web-app/bin/run test -- --filter CreateArchiveRouteTest
```

If the image is missing, build it from the repository root and install dependencies from this package:

```bash
./bin/docker-build
packages/web-app/bin/run install
```
