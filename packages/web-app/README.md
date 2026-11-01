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

A new archive answers 201 with `{"id":1,"url":"https://example.com/a","title":"..."}`. A second POST of the same URL answers 200 with the same body and does not fetch the page again.

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

## Probe

From `packages/web-app`, one command starts the development image, starts the server, runs the steps in `probe/probe.json`, writes a run folder, and stops the server.

```bash
export ELEANOR_BEARER_TOKEN=change-me
./bin/probe
```

The token is the same secret as `.env.local`. The command reads it from the shell. An empty token exits 1, prints `The bearer token is empty.` on the error output, and writes no run folder.

A broken setup, a server that does not start, or a step that could not be sent prints one sentence on the error output. An Eleanor status stays in the step file and does not print.

No argument runs every name in `steps`. One argument runs that step alone:

```bash
./bin/probe invalid-json
```

A second argument, or a name the catalog does not have, exits 1 before the server starts.

The folder is `probe/runs/<utc>-<seed>/`. `run.json` records the start, the owner, the seed, and the drawn URLs. Each step is a file under `steps/`, such as `steps/01-import.json`. The bearer is not written there.

`import`, `archive`, `archive-again`, `detail`, `detail-content`, `detail-html`, and `sql` draw at most 10 lines from `var/log-urls.txt`. That file is one URL per line and stays out of git. A run of `list` or of a refusal step does not read it. A required draw with a missing or empty file prints `The URL file is missing or empty.` and exits 1 before the folder is created.

`detail` requests `GET /api/archives/{id}` and records the metadata. `detail-content` adds `includeContent=true`, so the JSON includes `html`. `detail-html` requests `GET /api/archives/{id}.html` and records the stored HTML alone. `{id}` is the row stored for the first drawn URL. A missing row is recorded with `error` set and no response.

Stop `bin/serve` first. The probe binds `127.0.0.1:8000` itself. The owner in `probe/probe.json` must match `eleanor.owner_email`.

Exit 0 means setup held and every selected step was recorded without a tool failure. The command then prints `Wrote probe/runs/<utc>-<seed>`. An Eleanor status, including 400 and 401, still exits 0. A step that could not be sent is recorded with `error` set, the other steps still run, and the process exits 1 at the end.

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
