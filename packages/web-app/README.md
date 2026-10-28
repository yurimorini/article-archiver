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

A new archive answers 201 with `{"url":"https://example.com/a","title":"..."}`. A second POST of the same URL answers 200 with the same body and does not fetch the page again.

The archive belongs to the owner whose email is the `eleanor.owner_email` parameter in `config/services.yaml`. Change it there. That owner has one SQLite file under `var/data/`. The file keeps the archive across requests.

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

## Import

From `packages/web-app`, run the console inside the development image. The working directory is this package. Write the list yourself, one URL per line, and pass that path:

```bash
./bin/docker-run php bin/console archive:import notes/urls.txt --owner=owner@example.com
```

`--owner` is required. Use the same email as `eleanor.owner_email` in `config/services.yaml` when the archives should belong to the Owner of the HTTP API.

The command prints `Importing:` and the path, then one line per row: `ARCHIVED`, `ALREADY STORED`, `ERROR`, or `SKIPPED`, then the URL. A blank line is `SKIPPED` and `--`. It then prints `Written`, `Already stored`, `Failed`, and `Skipped`.

A URL this owner already has prints `ALREADY STORED` and is not fetched again. Each owner has one SQLite file under `var/data/`.

A failed row is also written to `error.log` in the working directory, and the command exits 1. The summary names that file. A later run with no failures removes `error.log`.

An empty `--owner` or a file that cannot be read fails before any archive. The cause is printed on the error output, and there are no totals.

If the image is missing, build it and install dependencies as in [Tests](#tests).

## Tests

From `packages/web-app`, Composer scripts run inside the development image:

```bash
./bin/run test
./bin/run quality
```

Pass PHPUnit arguments after `--`:

```bash
./bin/run test -- --filter CreateArchiveRouteTest
```

`./bin/run` always runs Composer. `./bin/docker-run` runs any other command in that image.

If the image is missing, build it from the repository root with `./bin/docker-build`, then install dependencies from this package:

```bash
./bin/run install
```
